<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ProfileRequest;
use App\Http\Resources\User\ProfileResource;

class ProfileController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(ProfileRequest $request): ProfileResource
    {
        $user = $request->user();

        return new ProfileResource($user);
    }
}
