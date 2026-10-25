<?php

namespace App\Http\Controllers\Social;

use App\Actions\Social\AcceptFriendRequest;
use App\Actions\Social\DismissFriendRequest;
use App\Actions\Social\RemoveFriend;
use App\Actions\Social\SendFriendRequest;
use App\Exceptions\WorldActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Responses\FriendsResponse;
use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The friends list and everything that changes it. Each answer is the whole
 * list again (see FriendsResponse).
 */
final class FriendsController extends Controller
{
    public function __construct(private readonly FriendsResponse $response) {}

    public function index(Request $request): JsonResponse
    {
        return $this->response->for($this->user($request));
    }

    public function send(Request $request, SendFriendRequest $sendFriendRequest): JsonResponse
    {
        $request->validate(['username' => ['required', 'string', 'max:32']]);

        $user = $this->user($request);
        $recipient = User::query()->whereRaw('lower(username) = lower(?)', [$request->string('username')->trim()->toString()])->first()
            ?? throw WorldActionRefused::friendUnknown();
        $friends = $sendFriendRequest($user, $recipient);

        return $this->response->for($user, $friends
            ? (string) __('hushfield.friendship_started', ['name' => $recipient->username])
            : (string) __('hushfield.friend_request_sent'), $friends ? 200 : 201);
    }

    public function accept(Request $request, FriendRequest $friendRequest, AcceptFriendRequest $acceptFriendRequest): JsonResponse
    {
        $user = $this->user($request);
        $acceptFriendRequest($user, $friendRequest);

        return $this->response->for($user, (string) __('hushfield.friendship_started', ['name' => $friendRequest->sender->username]));
    }

    public function dismiss(Request $request, FriendRequest $friendRequest, DismissFriendRequest $dismissFriendRequest): JsonResponse
    {
        $user = $this->user($request);
        $dismissFriendRequest($user, $friendRequest);

        return $this->response->for($user, (string) __('hushfield.friend_request_dismissed'));
    }

    public function remove(Request $request, User $friend, RemoveFriend $removeFriend): JsonResponse
    {
        $user = $this->user($request);
        $removeFriend($user, $friend);

        return $this->response->for($user, (string) __('hushfield.friend_removed'));
    }

    public function settings(Request $request): JsonResponse
    {
        $request->validate(['requests_allowed' => ['required', 'boolean']]);

        $user = $this->user($request);
        $user->forceFill(['friend_requests_allowed' => $request->boolean('requests_allowed')])->save();

        return $this->response->for($user, (string) __($user->friend_requests_allowed ? 'hushfield.friend_requests_on' : 'hushfield.friend_requests_off'));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
