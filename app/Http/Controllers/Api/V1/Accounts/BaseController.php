<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Http\Controllers\Controller;
use App\Support\Current;

/**
 * Controller BaseController
 * 
 * Controller base para rotas scoped por account.
 * O middleware EnsureAccountAccess já aplica SetCurrentAccount nas rotas.
 * 
 * @package App\Http\Controllers\Api\V1\Accounts
 */
class BaseController extends Controller
{
    /**
     * Retorna a account atual do contexto
     * 
     * @return \App\Models\Account
     */
    protected function getAccountProperty(): \App\Models\Account
    {
        return Current::account();
    }

    /**
     * Magic getter para acessar account como propriedade
     * 
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        if ($name === 'account') {
            return $this->getAccountProperty();
        }

        return null;
    }
}
