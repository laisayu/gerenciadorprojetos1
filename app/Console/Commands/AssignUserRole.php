<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AssignUserRole extends Command
{
    protected $signature = 'users:assign-role {email} {role : admin, manager ou member}';

    protected $description = 'Atribui um perfil a uma conta existente';

    public function handle(): int
    {
        $role = $this->argument('role');
        if (! in_array($role, ['admin', 'manager', 'member'], true)) {
            $this->error('Perfil inválido.');

            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }
        if ($user->isAdministrator() && $role !== 'admin' && User::role('admin')->count() <= 1) {
            $this->error('Mantenha pelo menos um administrador.');

            return self::FAILURE;
        }
        $user->syncRoles($role);
        $this->info('Perfil atribuído com sucesso.');

        return self::SUCCESS;
    }
}
