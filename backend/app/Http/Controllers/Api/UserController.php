<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * Read-only listing used to populate the "profissional responsável"
     * select on the appointment form. No CRUD here on purpose.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::orderBy('name')->get());
    }
}
