<?php

namespace AgenticActions;

/**
 * What a form asks the person beyond what schema() says, built in Action::ask(). A field's title is the schema's own
 * (title()), read in the context's locale.
 *
 * @api
 */
final class Ask
{
    /**
     * The sentence above the form.
     */
    private ?string $message = null;

    /**
     * The fields every form of the action shows.
     *
     * @var list<string>
     */
    private array $confirm = [];

    /**
     * Each field's choices, value => label.
     *
     * @var array<string, array<int|string, string>>
     */
    private array $choices = [];

    /**
     * Each field's authored default.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    /**
     * Each field's widget.
     *
     * @var array<string, 'textarea'>
     */
    private array $widgets = [];

    /**
     * The one sentence above the form: why these fields are needed. Plain text, no URL, at most 200 characters shown.
     */
    public function message(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Fields every form of this action shows, opening on the model's value when validation accepted it.
     */
    public function confirm(string ...$fields): static
    {
        $this->confirm = array_values(array_unique([...$this->confirm, ...$fields]));

        return $this;
    }

    /**
     * A field's choices, value => label, in order: one choice for a scalar field, several for a list field. Every
     * value must be one the field accepts; the action's own rules still decide.
     *
     * @param  array<int|string, string>  $choices
     */
    public function choices(string $field, array $choices): static
    {
        $this->choices[$field] = $choices;

        return $this;
    }

    /**
     * The value a field opens on when the model gave none that validation accepted.
     */
    public function default(string $field, mixed $value): static
    {
        $this->defaults[$field] = $value;

        return $this;
    }

    /**
     * Show these text fields as several lines.
     */
    public function textarea(string ...$fields): static
    {
        foreach ($fields as $field) {
            $this->widgets[$field] = 'textarea';
        }

        return $this;
    }

    /**
     * What Form::build() reads.
     *
     * @internal
     *
     * @return array{message: ?string, confirm: list<string>, choices: array<string, array<int|string, string>>, defaults: array<string, mixed>, widgets: array<string, 'textarea'>}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'confirm' => $this->confirm,
            'choices' => $this->choices,
            'defaults' => $this->defaults,
            'widgets' => $this->widgets,
        ];
    }
}
