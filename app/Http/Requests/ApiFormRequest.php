<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every API request. Validation failures are rendered by ApiExceptionRenderer
 * (422 { message, errors }); authorization failures as 403. Extend and define rules().
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
