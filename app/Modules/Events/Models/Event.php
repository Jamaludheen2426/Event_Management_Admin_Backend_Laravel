<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Models\Client;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An event a client hosts. Its organizer (organizer_client_id) may manage it
 * as the host would, except delete it.
 *
 * @property int $id
 * @property int $vendor_id
 * @property int $client_id
 * @property int|null $organizer_client_id
 * @property int|null $plan_id
 * @property int|null $event_category_id
 * @property string $name
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string $privacy
 * @property string $status
 * @property string|null $theme_id
 * @property string|null $contact_phone
 * @property string|null $organizer
 * @property string|null $organizer_image
 * @property string|null $invitation_image
 * @property array<string, int>|null $components
 * @property list<string>|null $component_order
 * @property string|null $qr_token
 * @property int $qr_version
 * @property Carbon|null $qr_issued_at
 * @property Carbon|null $created_at
 * @property-read Client $client
 * @property-read Plan|null $plan
 * @property-read EventCategory|null $category
 */
final class Event extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    protected $fillable = [
        'vendor_id', 'client_id', 'organizer_client_id', 'plan_id', 'event_category_id', 'name', 'host_one', 'host_two',
        'tagline', 'description', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone', 'venue_name',
        'venue_address', 'venue_landmark', 'venue_map_link', 'venue_image', 'venue_lat', 'venue_lng', 'ceremony_title',
        'ceremony_venue', 'ceremony_description', 'organizer', 'contact_phone', 'contact_email', 'footer_note', 'privacy',
        'status', 'theme_id', 'primary_color', 'secondary_color', 'cover_image', 'organizer_image', 'custom_image',
        'invitation_image', 'components', 'component_order', 'qr_token', 'qr_version', 'qr_issued_at', 'qr_style',
    ];

    protected $hidden = ['qr_token'];

    protected $attributes = ['privacy' => 'private', 'status' => 'upcoming', 'qr_version' => 1, 'qr_style' => 0];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'venue_lat' => 'decimal:7',
            'venue_lng' => 'decimal:7',
            'components' => 'array',
            'component_order' => 'array',
            'qr_version' => 'integer',
            'qr_style' => 'integer',
            'qr_issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id')->withoutGlobalScopes();
    }

    /** @return HasMany<EventMenuChoice, $this> */
    public function menuChoices(): HasMany
    {
        return $this->hasMany(EventMenuChoice::class);
    }

    /** @return HasOne<EventSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(EventSetting::class);
    }

    /** @return HasMany<Participant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * draft / cancelled as stored; otherwise past, live or upcoming from the
     * dates, compared as calendar days (v1 deriveStatus).
     */
    public function derivedStatus(): string
    {
        if (in_array($this->status, ['draft', 'cancelled'], true)) {
            return $this->status;
        }
        $start = ($this->start_date ?? $this->end_date)?->format('Y-m-d');
        $end = ($this->end_date ?? $this->start_date)?->format('Y-m-d');
        if ($start === null || $end === null) {
            return 'upcoming';
        }
        $today = today()->format('Y-m-d');

        return match (true) {
            $end < $today => 'past',
            $start <= $today => 'live',
            default => 'upcoming',
        };
    }
}
