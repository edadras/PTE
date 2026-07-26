<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Learning\Models\QuestionBank;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\QuestionBankResource;
use Illuminate\Http\Request;

final class QuestionBankController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $banks = QuestionBank::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($banks, QuestionBankResource::class);
    }
}
