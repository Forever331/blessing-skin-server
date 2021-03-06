<?php

use App\Events;
use App\Models\User;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class UserControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp()
    {
        parent::setUp();
        return $this->actAs('normal');
    }

    public function testIndex()
    {
        $user = factory(User::class)->create();
        factory(\App\Models\Player::class)->create(['uid' => $user->uid]);

        $players_count = option('score_per_player') / option('user_initial_score');
        $this->actAs($user)
            ->visit('/user')
            ->assertViewHas('user')
            ->assertViewHas('statistics')
            ->see(1 / $players_count * 100)    // Players
            ->see(0)               // Storage
            ->see(bs_announcement())
            ->see($user->score);

        $unverified = factory(User::class, 'unverified')->create();

        $this->actAs($unverified)
            ->visit('/user')
            ->dontSee(trans('user.verification.notice.title'));

        option(['require_verification' => true]);
        $this->actAs($unverified)
            ->visit('/user')
            ->see(trans('user.verification.notice.title'));
    }

    public function testSign()
    {
        option(['sign_score' => '50,50']);
        $user = factory(User::class)->create();

        // Success
        $this->actAs($user)
            ->post('/user/sign')
            ->seeJson([
                'errno' => 0,
                'msg' => trans('user.sign-success', ['score' => 50]),
                'score' => option('user_initial_score') + 50,
                'storage' => [
                    'percentage' => 0,
                    'total' => option('user_initial_score') + 50,
                    'used' => 0
                ],
                'remaining_time' => (int) option('sign_gap_time')
            ]);

        // Remaining time is greater than 0
        $this->post('/user/sign')
            ->seeJson([
                'errno' => 1,
                'msg' => trans(
                    'user.cant-sign-until',
                    [
                        'time' => option('sign_gap_time'),
                        'unit' => trans('user.time-unit-hour')
                    ]
                )
            ]);

        // Can sign after 0 o'clock
        option(['sign_after_zero' => true]);
        $diff = \Carbon\Carbon::now()->diffInSeconds(\Carbon\Carbon::tomorrow());
        $unit = '';
        if ($diff / 3600 >= 1) {
            $diff = round($diff / 3600);
            $unit = 'hour';
        } else {
            $diff = round($diff / 60);
            $unit = 'min';
        }
        $this->post('/user/sign')
            ->seeJson([
                'errno' => 1,
                'msg' => trans(
                    'user.cant-sign-until',
                    [
                        'time' => $diff,
                        'unit' => trans("user.time-unit-$unit")
                    ]
                )
            ]);

        $user->last_sign_at = \Carbon\Carbon::today()->toDateTimeString();
        $user->save();
        $this->post('/user/sign')
            ->seeJson([
                'errno' => 0
            ]);
    }

    public function testSendVerificationEmail()
    {
        $user = factory(User::class, 'unverified')->create();
        $verified = factory(User::class)->create();

        // Should be forbidden if account verification is disabled
        option(['require_verification' => false]);
        $this->actAs($user)
            ->post('/user/email-verification')
            ->seeJson([
                'errno' => 1,
                'msg' => trans('user.verification.disabled')
            ]);
        option(['require_verification' => true]);

        // Too fast
        $this->actAs($user)
            ->withSession([
                'last_mail_time' => time() - 10
            ])
            ->post('/user/email-verification')
            ->seeJson([
                'errno' => 1,
                'msg' => trans('user.verification.frequent-mail')
            ]);
        $this->flushSession();

        // Already verified
        $this->actAs($verified)
            ->post('/user/email-verification')
            ->seeJson([
                'errno' => 1,
                'msg' => trans('user.verification.verified')
            ]);

        // Should handle exception when sending email
        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new Mockery\Exception('A fake exception.'));
        $this->actAs($user)
            ->post('/user/email-verification')
            ->seeJson([
                'errno' => 2,
                'msg' => trans('user.verification.failed', ['msg' => 'A fake exception.'])
            ]);

        $user->fresh();
        $url = option('site_url')."/auth/verify?uid={$user->uid}&token={$user->verification_token}";

        Mail::shouldReceive('send')
            ->once()
            ->with(
                'mails.email-verification',
                Mockery::on(function ($actual) use ($url) {
                    $this->assertEquals(0, stristr($url, $actual['url']));
                    return true;
                }),
                Mockery::on(function (Closure $closure) use ($user) {
                    $mock = Mockery::mock(Illuminate\Mail\Message::class);

                    $mock->shouldReceive('from')
                        ->once()
                        ->with(config('mail.username'), option_localized('site_name'));

                    $mock->shouldReceive('to')
                        ->once()
                        ->with($user->email)
                        ->andReturnSelf();

                    $mock->shouldReceive('subject')
                        ->once()
                        ->with(trans('user.verification.mail.title', ['sitename' => option_localized('site_name')]));
                    $closure($mock);
                    return true;
                })
            );

        // Success
        $this->actAs($user)
            ->post('/user/email-verification')
            ->seeJson([
                'errno' => 0,
                'msg' => trans('user.verification.success')
            ])->assertSessionHas('last_mail_time');
    }

    public function testProfile()
    {
        $this->visit('/user/profile')
            ->assertViewHas('user');
    }

    public function testHandleProfile()
    {
        $user = factory(User::class)->create();
        $user->changePasswd('12345678');

        // Invalid action
        $this->actAs($user)
            ->post('/user/profile')
            ->seeJson([
                'errno' => 1,
                'msg' => trans('general.illegal-parameters')
            ]);

        // Change nickname without `new_nickname` field
        $this->post('/user/profile', [
            'action' => 'nickname'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'new nickname'])
        ]);

        // Invalid nickname
        $this->post('/user/profile', [
            'action' => 'nickname',
            'new_nickname' => '\\'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.no_special_chars', ['attribute' => 'new nickname'])
        ]);

        // Too long nickname
        $this->post('/user/profile', [
            'action' => 'nickname',
            'new_nickname' => str_random(256)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'new nickname', 'max' => 255])
        ]);

        // Change nickname successfully
        $this->expectsEvents(Events\UserProfileUpdated::class);
        $this->post('/user/profile', [
            'action' => 'nickname',
            'new_nickname' => 'nickname'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 0,
            'msg' => trans('user.profile.nickname.success', ['nickname' => 'nickname'])
        ]);
        $this->assertEquals('nickname', User::find($user->uid)->nickname);

        // Change password without `current_password` field
        $this->post('/user/profile', [
            'action' => 'password'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.required', ['attribute' => 'current password'])
        ]);

        // Too short current password
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => '1',
            'new_password' => '12345678'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'current password', 'min' => 6])
        ]);

        // Too long current password
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => str_random(33),
            'new_password' => '12345678'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'current password', 'max' => 32])
        ]);

        // Too short new password
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => '12345678',
            'new_password' => '1'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'new password', 'min' => 8])
        ]);

        // Too long new password
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => '12345678',
            'new_password' => str_random(33)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'new password', 'max' => 32])
        ]);

        // Wrong old password
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => '1234567',
            'new_password' => '87654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('user.profile.password.wrong-password')
        ]);

        // Change password successfully
        $this->expectsEvents(Events\EncryptUserPassword::class);
        $this->post('/user/profile', [
            'action' => 'password',
            'current_password' => '12345678',
            'new_password' => '87654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 0,
            'msg' => trans('user.profile.password.success')
        ]);
        $this->assertTrue(User::find($user->uid)->verifyPassword('87654321'));
        // After changed password, user should re-login.
        $this->visit('/user')->seePageIs('/auth/login');

        $user = User::find($user->uid);
        // Change email without `new_email` field
        $this->actAs($user)
            ->post(
                '/user/profile',
                ['action' => 'email'],
                ['X-Requested-With' => 'XMLHttpRequest'])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('validation.required', ['attribute' => 'new email'])
            ]);

        // Invalid email
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => 'not_an_email'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.email', ['attribute' => 'new email'])
        ]);

        // Too short current password
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => 'a@b.c',
            'password' => '1'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'password', 'min' => 6])
        ]);

        // Too long current password
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => 'a@b.c',
            'password' => str_random(33)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'password', 'max' => 32])
        ]);

        // Use a duplicated email
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => $user->email,
            'password' => '87654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('user.profile.email.existed')
        ]);

        // Wrong password
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => 'a@b.c',
            'password' => '7654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('user.profile.email.wrong-password')
        ]);

        // Change email successfully
        $this->post('/user/profile', [
            'action' => 'email',
            'new_email' => 'a@b.c',
            'password' => '87654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 0,
            'msg' => trans('user.profile.email.success')
        ]);
        $this->assertEquals('a@b.c', User::find($user->uid)->email);
        $this->assertEquals(0, User::find($user->uid)->verified);
        // After changed email, user should re-login.
        $this->visit('/user')->seePageIs('/auth/login');

        $user = User::find($user->uid);
        // Delete account without `password` field
        $this->actAs($user)
            ->post(
                '/user/profile',
                ['action' => 'delete'],
                ['X-Requested-With' => 'XMLHttpRequest'])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('validation.required', ['attribute' => 'password'])
            ]);

        // Too short current password
        $this->post('/user/profile', [
            'action' => 'delete',
            'password' => '1'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.min.string', ['attribute' => 'password', 'min' => 6])
        ]);

        // Too long current password
        $this->post('/user/profile', [
            'action' => 'delete',
            'password' => str_random(33)
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('validation.max.string', ['attribute' => 'password', 'max' => 32])
        ]);

        // Wrong password
        $this->post('/user/profile', [
            'action' => 'delete',
            'password' => '7654321'
        ], [
            'X-Requested-With' => 'XMLHttpRequest'
        ])->seeJson([
            'errno' => 1,
            'msg' => trans('user.profile.delete.wrong-password')
        ]);

        // Delete account successfully
        $this->post('/user/profile', [
            'action' => 'delete',
            'password' => '87654321'
        ])->seeJson([
            'errno' => 0,
            'msg' => trans('user.profile.delete.success')
        ])->seeCookie('uid', '')
            ->seeCookie('token', '');
        $this->assertNull(User::find($user->uid));
    }

    public function testSetAvatar()
    {
        $user = factory(User::class)->create();
        $steve = factory(\App\Models\Texture::class)->create();
        $cape = factory(\App\Models\Texture::class, 'cape')->create();

        // Without `tid` field
        $this->actAs($user)
            ->post('/user/profile/avatar', [], [
                'X-Requested-With' => 'XMLHttpRequest'
            ])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('validation.required', ['attribute' => 'tid'])
            ]);

        // TID is not a integer
        $this->actAs($user)
            ->post('/user/profile/avatar', [
                'tid' => 'string'
            ], [
                'X-Requested-With' => 'XMLHttpRequest'
            ])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('validation.integer', ['attribute' => 'tid'])
            ]);

        // Texture cannot be found
        $this->actAs($user)
            ->post('/user/profile/avatar', [
                'tid' => 0
            ])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('skinlib.non-existent')
            ]);

        // Use cape
        $this->actAs($user)
            ->post('/user/profile/avatar', [
                'tid' => $cape->tid
            ])
            ->seeJson([
                'errno' => 1,
                'msg' => trans('user.profile.avatar.wrong-type')
            ]);

        // Success
        $this->actAs($user)
            ->post('/user/profile/avatar', [
                'tid' => $steve->tid
            ])
            ->seeJson([
                'errno' => 0,
                'msg' => trans('user.profile.avatar.success')
            ]);
        $this->assertEquals($steve->tid, User::find($user->uid)->avatar);
    }
}
