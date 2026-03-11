<?php

namespace App\Services\Fortia;

class FortiaAuthService
{
    public function getToken(): string
    {
        // TODO: llamar endpoint /login/authenticate y cachear token
        return config('fortia.dummy_token', 'FORTIA-DUMMY-TOKEN');
    }
}
