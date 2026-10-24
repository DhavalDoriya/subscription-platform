<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ListRequest;

class ListEmailTemplatesRequest extends ListRequest
{
    public const SORTABLE = ['key', 'name', 'enabled', 'updated_at'];
}
