<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\UpdateProfileRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\Request;

final class MeController extends ApiController
{
    public function show(Request $request): StudentProfileResource
    {
        return StudentProfileResource::make($this->student($request));
    }

    public function update(UpdateProfileRequest $request): StudentProfileResource
    {
        $student = $this->student($request);

        $student->fill($request->validated())->save();

        return StudentProfileResource::make($student->refresh());
    }
}
