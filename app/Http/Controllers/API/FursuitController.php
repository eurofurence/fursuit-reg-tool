<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\FursuitSearchRequest;
use App\Http\Resources\FursuitCollection;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;


class FursuitController extends Controller
{
    private int $pageSize = 10; // Start paginate the returns based on $pageSize entrys per page

    /**
     * Expects an optional attendee_id, optional fursuit name and optional status
     * attendee_id: Returns all fursuits of the user with the corresponding ID
     * fursuit name: Returns all fursuits with which contains the term in its name. %NAME%
     * status: by default set to approved. Can be set for a specific search or set to "any" if status shall be ignored
     */

    public function index(FursuitSearchRequest $request) {
        $regID = $request->validated("reg_id");
        $name = $request->validated("name");
        $status = $request->validated("status") ?? Approved::$name;

        // start building Query
        $query = Fursuit::query()->withCount("badges")->with("species")->with("user");

        // Search for reg_id
        if ($request->exists("reg_id"))
            $query->whereHas("user", function(Builder $query) use ($regID) {
                $query->where("attendee_id", "=", $regID);
            });

        // Search for Fursuit Name
        if ($request->exists("name"))
            $query->where("name", 'like', '%'.$name.'%');

        // Search for status (approved by default) and not filtered when "any"
        if ($status != 'any')
            $query->where("status", '=', $status);

        // returns the results paginated and filteres by FursuitCollection
        return new FursuitCollection($query->paginate($this->pageSize));
    }
}
