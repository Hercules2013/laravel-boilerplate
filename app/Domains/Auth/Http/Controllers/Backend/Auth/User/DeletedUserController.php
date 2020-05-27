<?php

namespace App\Domains\Auth\Http\Controllers\Backend\Auth\User;

use App\Domains\Auth\Models\User;
use App\Http\Controllers\Controller;
use App\Services\UserService;

/**
 * Class DeletedUserController.
 */
class DeletedUserController extends Controller
{
    /**
     * @var UserService
     */
    protected $userService;

    /**
     * DeletedUserController constructor.
     *
     * @param  UserService  $userService
     */
    public function __construct(UserService $userService)
    {
        // TODO: keep?
        $this->middleware('permission:access.users.delete|access.users.restore|access.users.permanently-delete')->only('index');
        $this->middleware('permission:access.users.restore')->only('update');

        $this->userService = $userService;
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index()
    {
        return view('backend.auth.user.deleted');
    }

    /**
     * @param  User  $deletedUser
     *
     * @return mixed
     * @throws \App\Domains\Auth\Exceptions\GeneralException
     */
    public function update(User $deletedUser)
    {
        $this->userService->restore($deletedUser);

        return redirect()->route('admin.auth.user.index')->withFlashSuccess(__('The user was successfully restored.'));
    }

    /**
     * @param  User  $deletedUser
     *
     * @return mixed
     * @throws \App\Domains\Auth\Exceptions\GeneralException
     */
    public function destroy(User $deletedUser)
    {
        abort_unless(config('boilerplate.access.users.permanently_delete'), 404);

        $this->userService->permanentlyDelete($deletedUser);

        return redirect()->route('admin.auth.user.deleted')->withFlashSuccess(__('The user was permanently deleted.'));
    }
}
