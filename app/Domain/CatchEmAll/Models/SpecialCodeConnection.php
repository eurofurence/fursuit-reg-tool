<?php

namespace App\Domain\CatchEmAll\Models;

use App\Models\EventUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialCodeConnection extends Model
{
	protected $table = 'special_code_connection';

	public $incrementing = false;

	protected $fillable = [
		'special_code_id',
		'event_users_id',
	];

	public function specialCode(): BelongsTo
	{
		return $this->belongsTo(SpecialCode::class);
	}

	public function eventUser(): BelongsTo
	{
		return $this->belongsTo(EventUser::class, 'event_users_id');
	}
}
