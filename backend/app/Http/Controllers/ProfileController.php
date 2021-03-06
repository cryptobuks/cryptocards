<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeEmail;
use App\Models\Card;
use App\Models\Follow;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('jwt.auth', ['except' => ['getUserDetail', 'getAllUsers']]);
    }

    /**
     * Gets the info about the current user.
     *
     * @return mixed user
     */
    public function me()
    {
        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, auth()->user());
    }

    public function updateMe()
    {
        $user = auth()->user();
        $data = json_decode(Request::getContent(), true);

        $oldEmail = $user->email;

        foreach ($data as $key => $value) {
            if (in_array($key, [
                User::FIELD_EMAIL,
                User::FIELD_NICKNAME,
            ])) {
                $user->$key = $value;
            }
        }

        //check if there's another user with the nickname that the user wants to set theirs as
        if (User::where(USER::FIELD_NICKNAME, $user->nickname)->where('id', '!=', $user->id)->first()) {
            return response()->build(self::RESPONSE_MESSAGE_ERROR_DUPLICATE, 'A user exists with nickname '.$user->nickname);
        }

        //check if email changed
        if ($user->isDirty(User::FIELD_EMAIL)) {
            //check for other user having this email
            if (User::where(USER::FIELD_EMAIL, $user->email)->where('id', '!=', $user->id)->first()) {
                return response()->build(self::RESPONSE_MESSAGE_ERROR_DUPLICATE, 'A user exists with email '.$user->email);
            }

            if ($oldEmail == null) {
                //setting email for first time
                $user->save();
                Mail::to($user)->send(new WelcomeEmail($user));
            } elseif ($user->email = '') {
                //bad! setting email to blank, revert email so we can save the rest
                $user->email = $oldEmail;
            }
        }
        $user->save();

        return $this->me();
    }

    /**
     * Gets all the cards for a given user. Includes hidden cards if the authorized user is requesting their own profile.
     * Includes isFollowing, true if authorized user is following user_id.
     *
     * @return mixed cards
     */
    public function getUserDetail($userIdOrNickname)
    {
        $requestorUser = auth()->user();
        $user = User::where(User::FIELD_NICKNAME, $userIdOrNickname)->orWhere('id', $userIdOrNickname)->first();

        if (!$user) {
            //user not found
            return response()->build(self::RESPONSE_MESSAGE_ERROR_NOT_FOUND, "user not found: {$userIdOrNickname}");
        }

        $isRequestingMe = $requestorUser && ($requestorUser->id == $user->id);

        $cards = Card::with('attributes');
        $isFollowing = false;
        if (!$isRequestingMe) {
            //requesting another user, so hide their hidden cards and calculate if we are following them
            $cards = $cards->where(Card::FIELD_HIDDEN_TOGGLE, false);
            $isFollowing = $requestorUser && $requestorUser->followings->contains($user);
        }
        $cards = $cards->where('user_id', $user->id)->get();

        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, [
            'cards'        => $cards,
            'isFollowing'  => $isFollowing,
            'user'         => $user,
            'battleGroups' => $user->getAllBattleGroups(),
            'battles'      => $user->getAllBattles(),
        ]);
    }

    /**
     * authorized user follows user_id.
     *
     * @return const RESPONSE_MESSAGE_SUCCESS or RESPONSE_MESSAGE_ALREADY_FOLLOWING
     */
    public function follow($user_id)
    {
        if (auth()->user()->follow($user_id)) {
            return response()->build(self::RESPONSE_MESSAGE_SUCCESS);
        } else {
            return response()->build(self::RESPONSE_MESSAGE_ALREADY_FOLLOWING);
        }
    }

    /**
     * authorized user follows user_id.
     * Get all followers for the user.
     *
     * @return const RESPONSE_MESSAGE_SUCCESS or RESPONSE_MESSAGE_ALREADY_FOLLOWING
     */
    public function getFollowers()
    {
        $user_id = auth()->user()->id;

        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, User::find($user_id)->followers);
    }

    /**
     * authorized user follows user_id.
     * Get all followings of the user.
     *
     * @return const RESPONSE_MESSAGE_SUCCESS or RESPONSE_MESSAGE_ALREADY_FOLLOWING
     */
    public function getFollowings()
    {
        $user_id = auth()->user()->id;

        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, User::find($user_id)->followings);
    }

    /**
     * Gets all the transactions of the user's purchases.
     *
     * @return mixed transactions
     */
    public function getMyTransactions()
    {
        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, Transaction::where('user_id', auth()->user()->id)->get());
    }

    /**
     * Gets all notifications for a user.
     */
    public function getMyNotifications()
    {
        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, auth()->user()->notifications);
    }

    /**
     * Gets all the users, with their cards.
     *
     * @return mixed users
     */
    public function getAllUsers()
    {
        //TODO: hide hidden cards!
        return response()->build(self::RESPONSE_MESSAGE_SUCCESS, User::with('cards')->get());
    }
}
