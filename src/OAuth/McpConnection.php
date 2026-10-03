<?php

namespace AgenticActions\OAuth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * One OAuth client a person connected to one of the package's MCP URLs: the base path, or one tenant's path, with the
 * scopes they approved there. A Passport token of that client and person counts only there, within those scopes.
 *
 * @api
 *
 * @property string $client_id
 * @property list<string> $scopes
 * @property string $user_type
 * @property int|string $user_id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 */
final class McpConnection extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'agentic_mcp_connections';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['client_id', 'scopes'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = ['scopes' => 'array'];

    /**
     * Passport's connection, so the approval and revoke() change both packages' rows in one transaction.
     */
    public function getConnectionName(): ?string
    {
        $connection = parent::getConnectionName() ?? config('passport.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * The person's connections, newest first.
     *
     * @return Builder<self>
     */
    public static function for(Model $person): Builder
    {
        return self::query()->whereMorphedTo('user', $person)->latest();
    }

    /**
     * Passport's client.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Passport::clientModel(), 'client_id');
    }

    /**
     * The tenant the approved URL names; none for the base path.
     *
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The person who approved.
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Revoke this client's tokens for this person, then delete the connection, in one transaction. The client stays:
     * its app may connect again, through the consent screen.
     */
    public function revoke(): void
    {
        $this->getConnection()->transaction(function (): void {
            $this->revokeTokens();
            $this->delete();
        });
    }

    /**
     * Revoke this client's access tokens, their refresh tokens and its unused codes for this person; the row stays. It
     * reads only client_id and user_id, so it works on a connection not saved yet.
     *
     * @internal
     */
    public function revokeTokens(): void
    {
        $tokens = Passport::token()->newQuery()->where('client_id', $this->client_id)->where('user_id', $this->user_id);

        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->where('client_id', $this->client_id)->where('user_id', $this->user_id)->update(['revoked' => true]);
    }
}
