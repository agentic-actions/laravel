<?php

namespace Workbench\App\Http\Controllers;

use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\ConversationSlot;
use AgenticActions\Streaming\Transcript;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Ai\TeamAssistant;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * The confirmation route of docs/copilot.md, for one member of one team. The server chooses the conversation, one per
 * person and team with Actions::conversation(), before it reads the request: a person answers a card in the
 * conversation it was asked in, one team's history never reaches another team's page, and every call it names still
 * passes the team's own checks.
 */
final class TeamAssistantController
{
    /**
     * A turn: the member's words, or their answers to the cards the conversation is waiting on. The conversation is
     * opened inside respond(), so a refused request opens nothing.
     */
    public function stream(Request $request, Team $team): Responsable|Response
    {
        [$user, $conversation] = $this->member($request, $team);
        $agent = (new TeamAssistant($user, $team))->continueOrStart($conversation->id(), $user);
        $chat = ChatRequest::from($request, $agent);

        return $chat->respond(fn (ChatRequest $chat) => $agent
            ->continue($conversation->open(), as: $user)
            ->stream($chat)
            ->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));
    }

    /**
     * The reload: the stored words, and the card of a call still waiting, rebuilt now.
     */
    public function transcript(Request $request, Team $team): JsonResponse
    {
        [$user, $conversation] = $this->member($request, $team);
        $conversationId = $conversation->id();

        return response()->json([
            'messages' => $conversationId === null ? [] : Transcript::forUseChat(
                $conversationId,
                $user,
                agent: (new TeamAssistant($user, $team))->continue($conversationId, as: $user),
            ),
        ]);
    }

    /**
     * The signed-in member and their conversation with this team's assistant. A team they do not belong to reads as
     * not found.
     *
     * @return array{0: User, 1: ConversationSlot}
     */
    private function member(Request $request, Team $team): array
    {
        $user = $request->user();

        abort_unless($user instanceof User && $team->users()->whereKey($user->getKey())->exists(), 404);

        return [$user, Actions::conversation(TeamAssistant::class, $user, $team)];
    }
}
