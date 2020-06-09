<?php

namespace App\Domains\Auth\Http\Controllers\Backend\Auth\User;

use App\Domains\Auth\Http\Requests\Backend\Auth\User\StoreUserRequest;
use App\Domains\Auth\Http\Requests\Backend\Auth\User\UpdateUserRequest;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\PermissionService;
use App\Domains\Auth\Services\RoleService;
use App\Domains\Auth\Services\UserService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Class UserController.
 */
class UserController extends Controller
{
    /**
     * @var UserService
     */
    protected $userService;

    /**
     * @var RoleService
     */
    protected $roleService;

    /**
     * @var PermissionService
     */
    protected $permissionService;

    /**
     * UserController constructor.
     *
     * @param  UserService  $userService
     * @param  RoleService  $roleService
     * @param  PermissionService  $permissionService
     */
    public function __construct(UserService $userService, RoleService $roleService, PermissionService $permissionService)
    {
        $this->userService = $userService;
        $this->roleService = $roleService;
        $this->permissionService = $permissionService;
    }

    /**
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('backend.auth.user.index');
    }

    /**
     * @return mixed
     */
    public function create()
    {
        return view('backend.auth.user.create')
            ->withRoles($this->roleService->with('permissions')->get())
            ->withCategories($this->permissionService->getCategorizedPermissions())
            ->withGeneral($this->permissionService->getUncategorizedPermissions());
    }

    /**
     * @param  StoreUserRequest  $request
     *
     * @return mixed
     * @throws \App\Domains\Auth\Exceptions\GeneralException
     */
    public function store(StoreUserRequest $request)
    {
        $user = $this->userService->store($request->validated());

        return redirect()->route('admin.auth.user.show', $user)->withFlashSuccess(__('The user was successfully created.'));
    }

    /**
     * @param  User  $user
     *
     * @return mixed
     */
    public function show(User $user)
    {
        return view('backend.auth.user.show')
            ->withUser($user);
    }

    /**
     * @param  Request  $request
     * @param  User  $user
     *
     * @return mixed
     */
    public function edit(Request $request, User $user)
    {
        if ($user->isMasterAdmin() && !$request->user()->isMasterAdmin()) {
            return redirect()->route('admin.auth.user.index')->withFlashDanger(__('Only the administrator can update this user.'));
        }

        return view('backend.auth.user.edit')
            ->withUser($user)
            ->withRoles($this->roleService->with('permissions')->get())
            ->withCategories($this->permissionService->getCategorizedPermissions())
            ->withGeneral($this->permissionService->getUncategorizedPermissions())
            ->withUsedPermissions($user->permissions->modelKeys());
    }

    /**
     * @param  UpdateUserRequest  $request
     * @param  User  $user
     *
     * @return mixed
     * @throws \Throwable
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        if ($user->isMasterAdmin() && !$request->user()->isMasterAdmin()) {
            return redirect()->route('admin.auth.user.index')->withFlashDanger(__('Only the administrator can update this user.'));
        }

        $this->userService->update($user, $request->validated());

        return redirect()->route('admin.auth.user.show', $user)->withFlashSuccess(__('The user was successfully updated.'));
    }

    /**
     * @param  User  $user
     *
     * @return mixed
     * @throws \App\Domains\Auth\Exceptions\GeneralException
     */
    public function destroy(User $user)
    {
        $this->userService->delete($user);

        return redirect()->route('admin.auth.user.deleted')->withFlashSuccess(__('The user was successfully deleted.'));
    }
}
