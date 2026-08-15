<?php

namespace Modules\Course\Services;

use Modules\Course\Models\CourseLiveClass;

class LiveClassService
{
    public function createLiveClass(array $data)
    {
        return CourseLiveClass::create($data);
    }

    public function updateLiveClass(array $data, $id)
    {
        return CourseLiveClass::where('id', $id)->update($data);
    }
}
