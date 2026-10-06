<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What putting the app on a cloud host relies on (DEPLOY.md): logins and the cache kept in the database, and https
 * addresses behind the host's load balancer.
 */
class DeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_creates_the_tables_for_logins_and_the_cache_in_the_database(): void
    {
        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing");
        }

        // Laravel's own session columns, which its database session handler reads and writes.
        $this->assertTrue(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']));
    }

    public function test_the_cache_can_live_in_the_database(): void
    {
        // The rate limits count in the cache, so on a cloud host it can't be the container's disk.
        $cache = Cache::store('database');

        $cache->put('deployment-check', 'kept', 60);
        $this->assertSame('kept', $cache->get('deployment-check'));

        $cache->forget('deployment-check');
        $this->assertNull($cache->get('deployment-check'));
    }

    public function test_an_https_app_url_makes_every_address_https(): void
    {
        $this->assertStringStartsWith('http://', url('/dashboard'));

        config(['app.url' => 'https://crimeify.example.com']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/dashboard'));
        $this->assertStringStartsWith('https://', asset('css/site.css'));
    }

    public function test_the_footer_names_the_database_in_use_but_not_its_server(): void
    {
        config([
            'database.connections.sqlite.database' => 'CrimeifyDB',
            'database.connections.sqlite.host' => 'crimeify-sql.database.windows.net',
        ]);
        $this->signIn();

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Connected to <strong>CrimeifyDB</strong> on SQLite', false)
            ->assertDontSee('MyAppDB')
            ->assertDontSee('database.windows.net');
    }

    public function test_an_http_app_url_leaves_addresses_as_they_are(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/dashboard'));
    }
}
