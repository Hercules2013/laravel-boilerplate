<?php

namespace App\Repositories\Backend\Access\User;

use App\Models\Access\User\User;
use App\Exceptions\GeneralException;
use App\Exceptions\Backend\Access\User\UserNeedsRolesException;
use App\Repositories\Backend\Access\Role\RoleRepositoryContract;
use App\Repositories\Frontend\Access\User\UserRepositoryContract as FrontendUserRepositoryContract;

/**
 * Class EloquentUserRepository
 * @package App\Repositories\User
 */
class EloquentUserRepository implements UserRepositoryContract
{
    /**
     * @var RoleRepositoryContract
     */
    protected $role;

    /**
     * @var FrontendUserRepositoryContract
     */
    protected $user;

    /**
     * @param RoleRepositoryContract $role
     * @param FrontendUserRepositoryContract $user
     */
    public function __construct(
        RoleRepositoryContract $role,
        FrontendUserRepositoryContract $user
    )
    {
        $this->role = $role;
        $this->user = $user;
    }

    /**
     * @param  $id
     * @param  bool               $withRoles
     * @throws GeneralException
     * @return mixed
     */
    public function findOrThrowException($id, $withRoles = false)
    {
        if ($user = User::withTrashed()->find($id)) {
            if ($withRoles) {
                $user->load("roles");
            }

            return $user;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.not_found'));
    }

	/**
     * @param int $status
     * @param bool $trashed
     * @return mixed
     */
    public function getForDataTable($status = 1, $trashed = false) {
		/**
		 * Note: You must return deleted_at or the User getActionButtonsAttribute won't
		 * be able to differentiate what buttons to show for each row.
		 */
        if ($trashed == "true")
            return User::onlyTrashed()
                ->select(['id', 'name', 'email', 'status', 'confirmed', 'created_at', 'updated_at', 'deleted_at'])
                ->get();

        return User::select(['id', 'name', 'email', 'status', 'confirmed', 'created_at', 'updated_at', 'deleted_at'])
            ->where('status', $status)
            ->get();
    }

    /**
     * @param  $input
     * @param  $roles
     * @throws GeneralException
     * @throws UserNeedsRolesException
     * @return bool
     */
    public function create($input, $roles)
    {
        $user = $this->createUserStub($input);

        if ($user->save()) {
            //User Created, Validate Roles
            $this->validateRoleAmount($user, $roles['assignees_roles']);

            //Attach new roles
            $user->attachRoles($roles['assignees_roles']);

            //Send confirmation email if requested
            if (isset($input['confirmation_email']) && $user->confirmed == 0) {
                $this->user->sendConfirmationEmail($user->id);
            }

            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.create_error'));
    }

    /**
     * @param $id
     * @param $input
     * @param $roles
     * @return bool
     * @throws GeneralException
     */
    public function update($id, $input, $roles)
    {
        $user = $this->findOrThrowException($id);
        $this->checkUserByEmail($input, $user);

        if ($user->update($input)) {
            //For whatever reason this just wont work in the above call, so a second is needed for now
            $user->status    = isset($input['status']) ? 1 : 0;
            $user->confirmed = isset($input['confirmed']) ? 1 : 0;
            $user->save();

            $this->checkUserRolesCount($roles);
            $this->flushRoles($roles, $user);

            return true;
        }

        throw new GeneralException(trans('exceptions.backend.access.users.update_error'));
    }

    /**
     * @param  $id
     * @param  $input
     * @throws GeneralException
     * @return bool
     */
    public function updatePassword($id, $input)
    {
        $user = $this->findOrThrowException($id);
        $user->password = bcrypt($input['password']);
        
        if ($user->save())
            return true;

        throw new GeneralException(trans('exceptions.backend.access.users.update_password_error'));
    }

    /**
     * @param  $id
     * @throws GeneralException
     * @return bool
     */
    public function destroy($id)
    {
        if (auth()->id() == $id) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_delete_self'));
        }

        $user = $this->findOrThrowException($id);

        if ($user->delete())
            return true;

        throw new GeneralException(trans('exceptions.backend.access.users.delete_error'));
    }

    /**
     * @param  $id
     * @throws GeneralException
     * @return boolean|null
     */
    public function delete($id)
    {
        $user = $this->findOrThrowException($id, true);

		//Failsafe
		if (is_null($user->deleted_at))
			throw new GeneralException("This user must be deleted first before it can be destroyed permanently.");

        //Detach all roles & permissions
        $user->detachRoles($user->roles);

        try {
            $user->forceDelete();
        } catch (\Exception $e) {
            throw new GeneralException($e->getMessage());
        }
    }

    /**
     * @param  $id
     * @throws GeneralException
     * @return bool
     */
    public function restore($id)
    {
        $user = $this->findOrThrowException($id);

		//Failsafe
		if (is_null($user->deleted_at))
			throw new GeneralException("This user is not deleted so it can not be restored.");

        if ($user->restore())
            return true;

        throw new GeneralException(trans('exceptions.backend.access.users.restore_error'));
    }

    /**
     * @param  $id
     * @param  $status
     * @throws GeneralException
     * @return bool
     */
    public function mark($id, $status)
    {
        if (access()->id() == $id && $status == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.cant_deactivate_self'));
        }

        $user         = $this->findOrThrowException($id);
        $user->status = $status;

        if ($user->save())
            return true;

        throw new GeneralException(trans('exceptions.backend.access.users.mark_error'));
    }

    /**
     * Check to make sure at lease one role is being applied or deactivate user
     *
     * @param  $user
     * @param  $roles
     * @throws UserNeedsRolesException
     */
    private function validateRoleAmount($user, $roles)
    {
        //Validate that there's at least one role chosen, placing this here so
        //at lease the user can be updated first, if this fails the roles will be
        //kept the same as before the user was updated
        if (count($roles) == 0) {
            //Deactivate user
            $user->status = 0;
            $user->save();

            $exception = new UserNeedsRolesException();
            $exception->setValidationErrors(trans('exceptions.backend.access.users.role_needed_create'));

            //Grab the user id in the controller
            $exception->setUserID($user->id);
            throw $exception;
        }
    }

    /**
     * @param  $input
     * @param  $user
     * @throws GeneralException
     */
    private function checkUserByEmail($input, $user)
    {
        //Figure out if email is not the same
        if ($user->email != $input['email']) {
            //Check to see if email exists
            if (User::where('email', '=', $input['email'])->first()) {
                throw new GeneralException(trans('exceptions.backend.access.users.email_error'));
            }
        }
    }

    /**
     * @param $roles
     * @param $user
     */
    private function flushRoles($roles, $user)
    {
        //Flush roles out, then add array of new ones
        $user->detachRoles($user->roles);
        $user->attachRoles($roles['assignees_roles']);
    }

    /**
     * @param  $roles
     * @throws GeneralException
     */
    private function checkUserRolesCount($roles)
    {
        //User Updated, Update Roles
        //Validate that there's at least one role chosen
        if (count($roles['assignees_roles']) == 0) {
            throw new GeneralException(trans('exceptions.backend.access.users.role_needed'));
        }
    }

    /**
     * @param  $input
     * @return mixed
     */
    private function createUserStub($input)
    {
        $user                    = new User;
        $user->name              = $input['name'];
        $user->email             = $input['email'];
        $user->password          = bcrypt($input['password']);
        $user->status            = isset($input['status']) ? 1 : 0;
        $user->confirmation_code = md5(uniqid(mt_rand(), true));
        $user->confirmed         = isset($input['confirmed']) ? 1 : 0;
        return $user;
    }
}
