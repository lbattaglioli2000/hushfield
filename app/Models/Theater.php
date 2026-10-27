<?php

namespace App\Models;

use App\Domain\Cinema\HouseLights;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the VFA's theaters: what staff have set for it. Which rooms are
 * theaters, and their names, come from config('hushfield.cinema.theaters');
 * a row appears the first time staff change something.
 *
 * @property int $id
 * @property string $slug The theater's room slug.
 * @property HouseLights $house_lights
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'house_lights'])]
class Theater extends Model
{
    protected $attributes = ['house_lights' => 'auto'];

    protected function casts(): array
    {
        return ['house_lights' => HouseLights::class];
    }

    public static function lights(string $slug): HouseLights
    {
        return self::query()->where('slug', $slug)->value('house_lights') ?? HouseLights::Auto;
    }
}
