<?php

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Http\Requests\Manage\SpecialCodeRequest;

test('payload stores the selected special code type and derives the action class', function () {
    $request = new class (['event_id' => 7, 'type' => SpecialCodeType::BUG_BOUNTY->value, 'code' => 'ABCD5']) extends SpecialCodeRequest
    {
        protected array $validatedData;

        public function __construct(array $validatedData)
        {
            $this->validatedData = $validatedData;
        }

        public function validated($key = null, $default = null)
        {
            if ($key === null) {
                return $this->validatedData;
            }

            return $this->validatedData[$key] ?? $default;
        }

        public function input($key = null, $default = null)
        {
            if ($key === null) {
                return $this->validatedData;
            }

            return $this->validatedData[$key] ?? $default;
        }
    };

    expect($request->payload())->toMatchArray([
        'event_id' => 7,
        'type' => SpecialCodeType::BUG_BOUNTY,
        'code' => 'ABCD5',
    ]);
});
