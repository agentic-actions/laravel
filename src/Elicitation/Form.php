<?php

namespace AgenticActions\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Ask;
use AgenticActions\Exposure\Entry;
use Illuminate\Support\Str;
use JsonException;
use LogicException;

/**
 * The form a person fills for one paused model call: MCP's form-mode params, built on the server by Runner::preview()
 * from the action's advertised schema, the model's arguments and the fields validation refused.
 *
 * @internal
 */
final class Form
{
    /**
     * Create a form.
     *
     * @param  array{type: 'object', properties: array<string, array<string, mixed>>, required?: list<string>}  $schema  the requestedSchema, widget hints included
     * @param  list<string>  $fields  the form's keys, in the schema's order
     * @param  array<string, array<string, int|float|string>>  $values  per field with titled choices or a numeric list: each const => the value it stands for
     * @param  array<string, list<string>>  $errors  per field, the messages validation gave the call this form was built from
     * @param  string  $fingerprint  ApprovalClaims::fingerprint() of the model's arguments as the call carried them
     */
    public function __construct(
        public readonly Entry $entry,
        public readonly ActionContext $context,
        public readonly string $message,
        public readonly array $schema,
        public readonly array $fields,
        public readonly array $values,
        public readonly array $errors,
        public readonly string $fingerprint,
    ) {}

    /**
     * The form for a Read or Write call whose validation refused only fields a form can hold, or null when any refused
     * field is one it cannot (the call is then refused as before).
     *
     * @param  Action  $action  a fresh instance, never the one that read the model's arguments
     * @param  array<string, mixed>  $node  AdvertisedSchema::node() of that fresh instance
     * @param  array<string, mixed>  $arguments  the model's arguments, as the call carried them
     * @param  array<string, list<string>>  $errors  Outcome::errors() of the refused call
     *
     * @throws LogicException when ask() names a field the node lacks, or confirms a field a form cannot hold
     * @throws JsonException when the arguments cannot be encoded
     */
    public static function build(Entry $live, Action $action, ActionContext $context, array $node, array $arguments, array $errors): ?self
    {
        $ask = $action->ask(new Ask, $context)->toArray();
        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        foreach ([...$ask['confirm'], ...array_keys($ask['choices'] + $ask['defaults'] + $ask['widgets'])] as $field) {
            if (! is_array($properties[$field] ?? null)) {
                throw new LogicException("[{$live->class}] ask() names [{$field}], which agents are not offered.");
            }
        }

        $refused = array_values(array_unique(array_map(fn (int|string $key): string => Str::before((string) $key, '.'), array_keys($errors))));

        if (array_diff($refused, array_map(strval(...), array_keys($properties))) !== []) {
            return null;
        }

        $rules = $action->rules($context);
        $schema = $values = $messages = [];

        foreach ($properties as $key => $property) {
            $key = (string) $key;
            $asked = in_array($key, $refused, true);
            $confirmed = in_array($key, $ask['confirm'], true);

            if (! $asked && ! $confirmed) {
                continue;
            }

            $candidates = [
                ...(! $asked && array_key_exists($key, $arguments) ? [$arguments[$key]] : []),
                ...(array_key_exists($key, $ask['defaults']) ? [$ask['defaults'][$key]] : []),
            ];
            $own = $rules[$key] ?? [];

            try {
                $converted = FormSchema::property($key, (array) $property, $ask, $candidates, is_string($own) ? explode('|', $own) : (is_array($own) ? array_values($own) : [$own]));
            } catch (LogicException $exception) {
                throw new LogicException("[{$live->class}] {$exception->getMessage()}", previous: $exception);
            }

            if ($converted === null && $confirmed) {
                throw new LogicException("[{$live->class}] ask() confirms [{$key}], which a form cannot hold.");
            }

            if ($converted === null) {
                return null;
            }

            [$schema[$key], $map] = $converted;
            $values += $map === [] ? [] : [$key => $map];
        }

        foreach ($errors as $key => $lines) {
            $messages[Str::before((string) $key, '.')] = [...($messages[Str::before((string) $key, '.')] ?? []), ...$lines];
        }

        // A nullable field reads as optional, whatever the node's required list says.
        $required = array_values(array_filter(
            array_map(strval(...), (array) ($node['required'] ?? [])),
            fn (string $key): bool => isset($schema[$key]) && ! in_array('null', (array) ($properties[$key]['type'] ?? []), true),
        ));

        return new self(
            $live,
            $context,
            ApprovalCard::text($ask['message'] ?? (string) trans('agentic-actions::ask.message', [], $context->locale)),
            ['type' => 'object', 'properties' => $schema, ...($required === [] ? [] : ['required' => $required])],
            array_map(strval(...), array_keys($schema)),
            $values,
            $messages,
            ApprovalClaims::fingerprint($arguments),
        );
    }

    /**
     * MCP's form params: with the widget hints in the app, without them over MCP. An MCP re-ask opens each field on
     * the value the person gave when the action accepted it (as its const), and appends a refused field's messages to
     * its description.
     *
     * @param  array<string, mixed>  $values  content() of the answer the action's rules refused
     * @param  array<string, list<string>>  $errors  per field, the messages of that refusal
     * @return array{mode: 'form', message: string, requestedSchema: array<string, mixed>}
     */
    public function params(bool $hints = true, array $values = [], array $errors = []): array
    {
        $schema = $this->schema;

        foreach ($schema['properties'] as $key => $property) {
            $map = $this->values[$key] ?? [];
            $const = fn (mixed $value): mixed => ($found = array_search($value, $map, true)) === false ? $value : (string) $found;

            if (array_key_exists($key, $values) && ! isset($errors[$key])) {
                $property['default'] = is_array($values[$key]) ? array_map($const, $values[$key]) : $const($values[$key]);
            }

            if (isset($errors[$key])) {
                $property['description'] = ApprovalCard::text(implode(' ', [$property['description'] ?? '', ...$errors[$key]]));
            }

            $schema['properties'][$key] = $hints ? $property : array_diff_key($property, ['x-agentic-actions' => true]);
        }

        return ['mode' => 'form', 'message' => $this->message, 'requestedSchema' => $schema];
    }

    /**
     * The data-elicitation part for this call; labels.source names the app that asks (ask.source with app.name).
     *
     * @return array{type: 'data-elicitation', id: string, data: array{action: string, params: array<string, mixed>, labels: array{source: string, submit: string, decline: string, cancel: string}}}
     */
    public function part(string $toolCallId): array
    {
        $line = fn (string $key, array $replace = []): string => (string) trans('agentic-actions::ask.'.$key, $replace, $this->context->locale);

        return [
            'type' => 'data-elicitation',
            'id' => 'elicitation:'.$toolCallId,
            'data' => [
                'action' => $this->entry->name,
                'params' => $this->params(),
                'labels' => [
                    'source' => ApprovalCard::text($line('source', ['app' => (string) config('app.name')])),
                    'submit' => $line('submit'),
                    'decline' => $line('decline'),
                    'cancel' => $line('cancel'),
                ],
            ],
        ];
    }

    /**
     * The row sent instead of the form when no claim could be minted: ApprovalCard::refusedRow()'s shape, labelled
     * with the message.
     *
     * @return array{type: 'data-action', id: string, data: array<string, string>}
     */
    public function refusedRow(string $toolCallId): array
    {
        return [
            'type' => 'data-action',
            'id' => 'a:'.$toolCallId,
            'data' => [
                'action' => $this->entry->name,
                'label' => $this->message,
                'status' => 'refused',
                'effect' => (string) $this->entry->effect?->value,
                'note' => (string) trans('agentic-actions::activity.refused', [], $this->context->locale),
            ],
        ];
    }

    /**
     * What a claim binds: "form", the action, actorKey(), tenantKey(), the conversation, the call and the fingerprint,
     * one per line. No text of the form: the action's rules check every answer.
     */
    public function binding(string $conversationId, string $toolCallId): string
    {
        return implode("\n", [
            'form', $this->entry->name, $this->context->actorKey(), $this->context->tenantKey(), $conversationId, $toolCallId, $this->fingerprint,
        ]);
    }

    /**
     * An accept's content, a JSON object, as the values the action runs with: keys outside the form dropped, null, ''
     * and a string of only whitespace dropped (false and [] kept: they are answers), each const turned back into the
     * value it stands for. Types, formats, choices and required fields are the dry run's to check, with the action's
     * own rules.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function content(array $content): array
    {
        $values = [];

        foreach ($this->fields as $field) {
            $value = $content[$field] ?? null;
            $map = $this->values[$field] ?? [];
            $mapped = fn (mixed $value): mixed => (is_string($value) || is_int($value)) && array_key_exists($value, $map) ? $map[$value] : $value;

            if ($value !== null && ! (is_string($value) && Str::ltrim($value) === '')) {
                $values[$field] = is_array($value) ? array_map($mapped, $value) : $mapped($value);
            }
        }

        return $values;
    }

    /**
     * The model's arguments without the form's fields, plus the person's values: a field the person left empty is
     * left out, never filled from the model.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function merge(array $arguments, array $values): array
    {
        $fields = array_flip($this->fields);

        return array_intersect_key($values, $fields) + array_diff_key($arguments, $fields);
    }

    /**
     * model.form_filled with the keys the person gave a value, in the context's locale: the one sentence every door
     * prefixes to the action's own.
     *
     * @param  array<string, mixed>  $values
     */
    public function filled(array $values): string
    {
        $filled = array_filter($this->fields, fn (string $field): bool => ($values[$field] ?? '') !== '');

        return (string) trans('agentic-actions::model.form_filled', ['fields' => implode(', ', $filled)], $this->context->locale);
    }
}
