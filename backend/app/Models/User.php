<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'profile_photo_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Caminho interno do disco — o front-end só precisa da URL pronta
        // (profile_photo_url, abaixo).
        'profile_photo_path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * URL pública pronta para o front-end usar direto num <img src>. Foto
     * fica no disco "public" (storage/app/public, link simbólico em
     * public/storage via "php artisan storage:link") — só o caminho é
     * guardado no banco, nunca o arquivo em si.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profile_photo_path
                ? Storage::disk('public')->url($this->profile_photo_path)
                : null,
        );
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<StatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'changed_by');
    }
}
