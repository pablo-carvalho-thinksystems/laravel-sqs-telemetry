<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Pablocarvalho\SqsTelemetry\Services\QueryBindingSanitizer;
use Pablocarvalho\SqsTelemetry\Services\TimelineContext;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class QueryBindingSanitizerTest extends TestCase
{
    private function sanitizer(): QueryBindingSanitizer
    {
        return $this->app->make(QueryBindingSanitizer::class);
    }

    public function test_ordinary_bindings_are_kept_on_every_table()
    {
        $this->assertSame([42], $this->sanitizer()->sanitize('select * from "users" where "users"."id" = ? limit 1', [42]));
        $this->assertSame(['a@b.c'], $this->sanitizer()->sanitize('select * from "users" where "email" = ? limit 1', ['a@b.c']));
        $this->assertSame([7, 'open'], $this->sanitizer()->sanitize(
            'select * from "funcionario_notificacoes" where "funcionario_id" = ? and "status" = ?',
            [7, 'open']
        ));
    }

    public function test_session_id_and_payload_are_masked_but_the_rest_of_the_row_is_kept()
    {
        $this->assertSame(['[REDACTED]'], $this->sanitizer()->sanitize('select * from "sessions" where "id" = ? limit 1', ['abc']));
        $this->assertSame([1790000000], $this->sanitizer()->sanitize('delete from "sessions" where "last_activity" <= ?', [1790000000]));

        $this->assertSame(
            ['[REDACTED]', 1790000000, 5, '10.0.0.1', 'curl', '[REDACTED]'],
            $this->sanitizer()->sanitize(
                'update "sessions" set "payload" = ?, "last_activity" = ?, "user_id" = ?, "ip_address" = ?, "user_agent" = ? where "id" = ?',
                ['eyJ...', 1790000000, 5, '10.0.0.1', 'curl', 'abc']
            )
        );

        $this->assertSame(
            ['[REDACTED]', 1790000000, 5, '10.0.0.1', 'curl', '[REDACTED]'],
            $this->sanitizer()->sanitize(
                'insert into "sessions" ("payload", "last_activity", "user_id", "ip_address", "user_agent", "id") values (?, ?, ?, ?, ?, ?)',
                ['eyJ...', 1790000000, 5, '10.0.0.1', 'curl', 'abc']
            )
        );
    }

    public function test_id_is_only_masked_on_the_session_table()
    {
        $this->assertSame([3], $this->sanitizer()->sanitize('select * from "plans" where "id" = ?', [3]));
        $this->assertSame(['[REDACTED]'], $this->sanitizer()->sanitize('select * from "users" inner join "sessions" on 1 = 1 where "sessions"."id" = ?', ['abc']));
    }

    public function test_credential_columns_are_masked_wherever_they_appear()
    {
        $this->assertSame(
            ['[REDACTED]', 9],
            $this->sanitizer()->sanitize('update "users" set "remember_token" = ? where "id" = ?', ['tok', 9])
        );
        $this->assertSame(
            ['[REDACTED]'],
            $this->sanitizer()->sanitize('select * from "personal_access_tokens" where "token" = ? limit 1', ['sha'])
        );
    }

    public function test_placeholders_are_paired_with_the_right_column_after_in_and_between()
    {
        // Antes, só `col = ?` era contado: um `in (?, ?)` antes do token
        // deslocava o índice e mascarava a coluna errada.
        $this->assertSame(
            ['a', 'b', '2026-01-01', '2026-02-01', '[REDACTED]'],
            $this->sanitizer()->sanitize(
                'select * from "users" where "status" in (?, ?) and "created_at" between ? and ? and "remember_token" = ?',
                ['a', 'b', '2026-01-01', '2026-02-01', 'tok']
            )
        );
        $this->assertSame(
            ['x', '[REDACTED]'],
            $this->sanitizer()->sanitize('select * from "users" where "name" like ? and "password" <> ?', ['x', 'p'])
        );
    }

    public function test_question_marks_in_literals_and_jsonb_operators_are_not_placeholders()
    {
        $this->assertSame(
            ['[REDACTED]'],
            $this->sanitizer()->sanitize("select * from \"users\" where \"note\" = 'why?' and \"meta\" ?? 'k' and \"password\" = ?", ['p'])
        );
    }

    public function test_multi_row_insert_and_upsert_tail()
    {
        $this->assertSame(
            ['a@b.c', '[REDACTED]', 'c@d.e', '[REDACTED]'],
            $this->sanitizer()->sanitize('insert into "users" ("email", "password") values (?, ?), (?, ?)', ['a@b.c', 'p1', 'c@d.e', 'p2'])
        );
        $this->assertSame(
            ['a@b.c', '[REDACTED]', '[REDACTED]'],
            $this->sanitizer()->sanitize(
                'insert into "users" ("email", "password") values (?, ?) on conflict ("email") do update set "password" = ?',
                ['a@b.c', 'p1', 'p1']
            )
        );
    }

    public function test_unpaired_placeholders_keep_their_values()
    {
        $this->assertSame([5, 10], $this->sanitizer()->sanitize('select * from "t" where lower("name") = coalesce(?, ?)', [5, 10]));
    }

    public function test_custom_session_table_is_protected()
    {
        config()->set('session.table', 'web_sessions');
        $this->app->forgetInstance(QueryBindingSanitizer::class);

        $this->assertSame(['[REDACTED]'], $this->sanitizer()->sanitize('select * from "web_sessions" where "id" = ?', ['abc']));
    }

    public function test_published_config_without_redact_key_still_protects_sessions()
    {
        // Projetos com config/sqs-telemetry.php publicado antes desta versão
        // não têm a chave `redact`.
        config()->set('sqs-telemetry.redact', null);
        $this->app->forgetInstance(QueryBindingSanitizer::class);

        $this->assertSame(['[REDACTED]'], $this->sanitizer()->sanitize('select * from "sessions" where "id" = ?', ['abc']));
        $this->assertSame([1], $this->sanitizer()->sanitize('select * from "users" where "id" = ?', [1]));
    }

    public function test_listener_ships_bindings_for_sessions_and_users_queries()
    {
        config()->set('sqs-telemetry.timeline.db', true);
        config()->set('sqs-telemetry.timeline.db_bindings', true);

        $timeline = $this->app->make(TimelineContext::class);
        $timeline->startRequest();
        $connection = $this->app['db']->connection();

        event(new QueryExecuted('select * from "sessions" where "id" = ? limit 1', ['abc'], 1.0, $connection));
        event(new QueryExecuted('select * from "users" where "id" = ? limit 1', [42], 1.0, $connection));

        $queries = array_values(array_filter($timeline->getTimeline(), function ($event) {
            return $event['type'] === 'db_query';
        }));

        $this->assertSame(['[REDACTED]'], $queries[0]['context']['bindings']);
        $this->assertSame([42], $queries[1]['context']['bindings']);
    }
}
