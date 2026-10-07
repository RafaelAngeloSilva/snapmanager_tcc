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
use Illuminate\Support\Facades\URL;
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
     * URL pronta para o front-end usar direto num <img src>.
     *
     * A foto fica no armazenamento persistente (local ou S3 privado, via
     * FileStorage) e é servida pelo Laravel numa rota com ASSINATURA
     * temporária (ProfilePhotoController) — um <img> não envia o token de
     * login, e o bucket nunca é público. A URL:
     *   - vale ~24 h e é estável dentro da mesma hora (o navegador reaproveita
     *     o cache em vez de baixar a foto a cada tela);
     *   - muda quando a foto muda (parâmetro "v", derivado da object key);
     *   - é assinada só sobre o caminho (signed:relative), então funciona
     *     atrás do proxy HTTPS do Railway sem depender do esquema/host.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->profile_photo_path
                ? url(URL::temporarySignedRoute(
                    'profile-photos.show',
                    now()->startOfHour()->addHours(25),
                    ['user' => $this->getKey(), 'v' => substr(sha1($this->profile_photo_path), 0, 10)],
                    absolute: false,
                ))
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
