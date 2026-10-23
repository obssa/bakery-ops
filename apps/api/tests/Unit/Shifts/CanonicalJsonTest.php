<?php

declare(strict_types=1);

use App\Modules\Shifts\Hash\CanonicalJson;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

pest()->group('unit', 'module:shifts');

/*
 * The canonical form of ADR 0012 / phase-3 plan section 10.3: an encoder of its own for a closed set of types, so the bytes that are
 * hashed do not depend on json_encode's flags, on a PHP version or on a library. UTF-8, no whitespace outside strings, map keys sorted
 * by byte order, decimals as fixed-scale strings (never "-0.0000"), timestamps in UTC with exactly six fraction digits, strings escaped
 * minimally. Three full payloads are pinned below and written to tests/Fixtures/chain/vectors.json (UPDATE_CHAIN_FIXTURES=1);
 * scripts/verify_chain.py, a second implementation with no PHP in it, recomputes them in CI.
 */

/** @return array<string, array{payload: array<string, mixed>, canonical: string}> */
function chainVectors(): array
{
    $stock = [
        'branch_id' => 1, 'location_id' => 5, 'shift_instance_id' => 17, 'shift_date' => '2026-11-02', 'station_id' => 3, 'type' => 'closing', 'seq' => 1,
        'supersedes_count_id' => null, 'snapshot_at' => '2026-11-02T12:00:00.123456Z', 'ledger_movement_id' => 811, 'counted_by_user_id' => 9,
        'cosigned_by_user_id' => null, 'decision' => 'approved', 'decided_by_user_id' => 2, 'decided_at' => '2026-11-02T12:30:00.000000Z',
        'decision_note' => 'Checked the store room', 'submitted_at' => '2026-11-02T12:00:00.000000Z',
        'lines' => [
            [
                'item_id' => 31, 'expected_qty' => '10000.0000', 'carried_qty' => null, 'counted_qty' => '9500.0000', 'variance_qty' => '-500.0000',
                'reason_code' => 'spoilage', 'reason_note' => null, 'adjustment_movement_id' => 812, 'adjustment_qty' => '-500.0000',
            ],
            [
                'item_id' => 32, 'expected_qty' => '300.0000', 'carried_qty' => null, 'counted_qty' => '300.0000', 'variance_qty' => '0.0000',
                'reason_code' => null, 'reason_note' => null, 'adjustment_movement_id' => null, 'adjustment_qty' => null,
            ],
        ],
        'cash' => null, 'chain_seq' => 4, 'locked_at' => '2026-11-02T12:30:00.482913Z', 'locked_by_user_id' => 2, 'payload_version' => 1,
    ];
    $till = [
        'branch_id' => 1, 'location_id' => 9, 'shift_instance_id' => 21, 'shift_date' => '2026-11-03', 'station_id' => 1, 'type' => 'opening', 'seq' => 2,
        'supersedes_count_id' => 40, 'snapshot_at' => '2026-11-03T03:00:00.000001Z', 'ledger_movement_id' => 902, 'counted_by_user_id' => 6,
        'cosigned_by_user_id' => 8, 'decision' => null, 'decided_by_user_id' => null, 'decided_at' => null, 'decision_note' => null,
        'submitted_at' => '2026-11-03T03:00:00.000000Z', 'lines' => [],
        'cash' => [
            'denominations' => [200 => 6, 100 => 5, 10 => 4, 5 => 2], 'counted_total' => '1750.50', 'expected_total' => '1750.50', 'variance_total' => '0.00',
            'carried_float_total' => '500.00', 'cash_sales_total' => '1250.50', 'float_left_total' => '500.00',
            'channels' => [['code' => 'cash', 'expected_receipts' => 12, 'expected_sum' => '1250.50', 'declared_receipts' => 12, 'declared_sum' => '1250.50', 'difference_sum' => '0.00']], 'reason_code' => null, 'reason_note' => null, 'currency' => 'ETB',
        ],
        'chain_seq' => 1, 'locked_at' => '2026-11-03T03:05:09.000000Z', 'locked_by_user_id' => 8, 'payload_version' => 1,
    ];
    $note = $stock;
    $note['location_id'] = 6;
    $note['seq'] = 3;
    $note['chain_seq'] = 2;
    $note['decision_note'] = "ስኳር አልቋል \"quoted\" back\\slash /slash\x01tab\t\x7F\u{2028} 😀";
    $note['lines'] = [];

    $head = '{"branch_id":1,"cash":';
    $stockCanonical = $head.'null,"chain_seq":4,"cosigned_by_user_id":null,"counted_by_user_id":9,"decided_at":"2026-11-02T12:30:00.000000Z","decided_by_user_id":2,'
        .'"decision":"approved","decision_note":"Checked the store room","ledger_movement_id":811,"lines":['
        .'{"adjustment_movement_id":812,"adjustment_qty":"-500.0000","carried_qty":null,"counted_qty":"9500.0000","expected_qty":"10000.0000","item_id":31,"reason_code":"spoilage","reason_note":null,"variance_qty":"-500.0000"},'
        .'{"adjustment_movement_id":null,"adjustment_qty":null,"carried_qty":null,"counted_qty":"300.0000","expected_qty":"300.0000","item_id":32,"reason_code":null,"reason_note":null,"variance_qty":"0.0000"}],'
        .'"location_id":5,"locked_at":"2026-11-02T12:30:00.482913Z","locked_by_user_id":2,"payload_version":1,"seq":1,"shift_date":"2026-11-02","shift_instance_id":17,'
        .'"snapshot_at":"2026-11-02T12:00:00.123456Z","station_id":3,"submitted_at":"2026-11-02T12:00:00.000000Z","supersedes_count_id":null,"type":"closing"}';
    $tillCanonical = $head.'{"carried_float_total":"500.00","cash_sales_total":"1250.50","channels":[{"code":"cash","declared_receipts":12,"declared_sum":"1250.50","difference_sum":"0.00","expected_receipts":12,"expected_sum":"1250.50"}],"counted_total":"1750.50",'
        .'"currency":"ETB","denominations":{"10":4,"100":5,"200":6,"5":2},"expected_total":"1750.50","float_left_total":"500.00","reason_code":null,"reason_note":null,"variance_total":"0.00"},'
        .'"chain_seq":1,"cosigned_by_user_id":8,"counted_by_user_id":6,"decided_at":null,"decided_by_user_id":null,"decision":null,"decision_note":null,"ledger_movement_id":902,"lines":[],'
        .'"location_id":9,"locked_at":"2026-11-03T03:05:09.000000Z","locked_by_user_id":8,"payload_version":1,"seq":2,"shift_date":"2026-11-03","shift_instance_id":21,'
        .'"snapshot_at":"2026-11-03T03:00:00.000001Z","station_id":1,"submitted_at":"2026-11-03T03:00:00.000000Z","supersedes_count_id":40,"type":"opening"}';
    $noteCanonical = $head.'null,"chain_seq":2,"cosigned_by_user_id":null,"counted_by_user_id":9,"decided_at":"2026-11-02T12:30:00.000000Z","decided_by_user_id":2,'
        .'"decision":"approved","decision_note":"ስኳር አልቋል \"quoted\" back\\\\slash /slash\u0001tab\u0009'."\x7F\u{2028} 😀".'","ledger_movement_id":811,"lines":[],'
        .'"location_id":6,"locked_at":"2026-11-02T12:30:00.482913Z","locked_by_user_id":2,"payload_version":1,"seq":3,"shift_date":"2026-11-02","shift_instance_id":17,'
        .'"snapshot_at":"2026-11-02T12:00:00.123456Z","station_id":3,"submitted_at":"2026-11-02T12:00:00.000000Z","supersedes_count_id":null,"type":"closing"}';

    return [
        'stock_count' => ['payload' => $stock, 'canonical' => $stockCanonical],
        'till_count_with_cash' => ['payload' => $till, 'canonical' => $tillCanonical],
        'unicode_note' => ['payload' => $note, 'canonical' => $noteCanonical],
    ];
}

it('encodes the three pinned payloads to the exact bytes', function (string $name) {
    $vector = chainVectors()[$name];

    expect(CanonicalJson::encode($vector['payload']))->toBe($vector['canonical']);
})->with(['stock_count', 'till_count_with_cash', 'unicode_note']);

it('matches the fixture the independent Python implementation recomputes, and rewrites it on request', function () {
    $links = [];
    $previous = str_repeat('0', 64);
    foreach (chainVectors() as $name => $vector) {
        $canonical = CanonicalJson::encode($vector['payload']);
        $hash = hash('sha256', $previous.$canonical);
        $links[] = ['name' => $name, 'payload' => $vector['payload'], 'canonical' => $canonical, 'previous_hash' => $previous, 'payload_hash' => $hash];
        $previous = $hash;
    }
    $document = json_encode(['format' => 'bakery-chain-vectors', 'version' => 1, 'vectors' => $links], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $path = dirname(__DIR__, 2).'/Fixtures/chain/vectors.json';
    if (getenv('UPDATE_CHAIN_FIXTURES') === '1') {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $document);
    }

    expect(is_file($path))->toBeTrue('run once with UPDATE_CHAIN_FIXTURES=1 to write the fixture')
        ->and(file_get_contents($path))->toBe($document);
});

it('sorts map keys by byte order at every depth, whatever the insertion order', function () {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['z' => true, 'y' => false], 'B' => null]))->toBe('{"B":null,"a":{"y":false,"z":true},"b":1}')
        ->and(CanonicalJson::encode(['cosigned' => 1, 'counted' => 2]))->toBe('{"cosigned":1,"counted":2}', '"cos" sorts before "cou"')
        ->and(CanonicalJson::encode([5 => 'a', 10 => 'b', 100 => 'c', 200 => 'd']))->toBe('{"10":"b","100":"c","200":"d","5":"a"}', 'integer-like keys sort as strings');
});

it('keeps list order and writes no whitespace', function () {
    expect(CanonicalJson::encode([3, 1, 2]))->toBe('[3,1,2]')
        ->and(CanonicalJson::encode([]))->toBe('[]')
        ->and(CanonicalJson::encode([['a' => 1], ['a' => 2]]))->toBe('[{"a":1},{"a":2}]')
        ->and(CanonicalJson::encode(null))->toBe('null')
        ->and(CanonicalJson::encode(true))->toBe('true')
        ->and(CanonicalJson::encode(-7))->toBe('-7')
        ->and(CanonicalJson::encode(0))->toBe('0');
});

it('escapes only the quote, the backslash and U+0000 to U+001F, with lowercase hex', function () {
    expect(CanonicalJson::encode('a"b\\c'))->toBe('"a\\"b\\\\c"')
        ->and(CanonicalJson::encode("\x00\x1f\n\r\t\x08\x0c"))->toBe('"\u0000\u001f\u000a\u000d\u0009\u0008\u000c"')
        ->and(CanonicalJson::encode('a/b'))->toBe('"a/b"')
        ->and(CanonicalJson::encode("\x7F"))->toBe("\"\x7F\"")
        ->and(CanonicalJson::encode("\u{2028}\u{2029}"))->toBe("\"\u{2028}\u{2029}\"")
        ->and(CanonicalJson::encode('ሰላም 😀'))->toBe('"ሰላም 😀"')
        ->and(CanonicalJson::encode("e\u{0301}"))->toBe("\"e\u{0301}\"", 'no Unicode normalisation');
});

it('refuses what is not in the grammar: floats, objects, invalid UTF-8', function () {
    expect(fn () => CanonicalJson::encode(1.5))->toThrow(LogicException::class)
        ->and(fn () => CanonicalJson::encode(['a' => new stdClass]))->toThrow(LogicException::class)
        ->and(fn () => CanonicalJson::encode(Quantity::of('1')))->toThrow(LogicException::class)
        ->and(fn () => CanonicalJson::encode("\xB1\x31"))->toThrow(LogicException::class)
        ->and(fn () => CanonicalJson::encode(NAN))->toThrow(LogicException::class);
});

it('writes quantities with four decimals, money with two, and zero never as negative zero', function () {
    expect(CanonicalJson::quantity('12.5'))->toBe('12.5000')
        ->and(CanonicalJson::quantity(Quantity::of('0')))->toBe('0.0000')
        ->and(CanonicalJson::quantity('-0'))->toBe('0.0000')
        ->and(CanonicalJson::quantity('-0.0000'))->toBe('0.0000')
        ->and(CanonicalJson::quantity('-500'))->toBe('-500.0000')
        ->and(CanonicalJson::quantity('0.1'))->toBe('0.1000')
        ->and(CanonicalJson::quantity('9999999999.9999'))->toBe('9999999999.9999')
        ->and(CanonicalJson::quantity(null))->toBeNull()
        ->and(CanonicalJson::money('1500'))->toBe('1500.00')
        ->and(CanonicalJson::money('-0.00'))->toBe('0.00')
        ->and(CanonicalJson::money('-2.5'))->toBe('-2.50')
        ->and(CanonicalJson::money(null))->toBeNull()
        ->and(fn () => CanonicalJson::quantity('1.00001'))->toThrow(LogicException::class)
        ->and(fn () => CanonicalJson::money('1.005'))->toThrow(LogicException::class);
});

it('writes timestamps in UTC with exactly six fraction digits and dates as YYYY-MM-DD', function () {
    expect(CanonicalJson::timestamp(CarbonImmutable::parse('2026-11-02 12:30:00', 'UTC')))->toBe('2026-11-02T12:30:00.000000Z')
        ->and(CanonicalJson::timestamp(CarbonImmutable::parse('2026-11-02 12:30:00.482913', 'UTC')))->toBe('2026-11-02T12:30:00.482913Z')
        ->and(CanonicalJson::timestamp(CarbonImmutable::parse('2026-11-02 15:30:00.5', 'Africa/Addis_Ababa')))->toBe('2026-11-02T12:30:00.500000Z', 'converted to UTC')
        ->and(CanonicalJson::timestamp('2026-11-02 12:30:00'))->toBe('2026-11-02T12:30:00.000000Z', 'a database string is UTC')
        ->and(CanonicalJson::timestamp('2026-11-02 12:30:00.000100'))->toBe('2026-11-02T12:30:00.000100Z')
        ->and(CanonicalJson::timestamp(null))->toBeNull()
        ->and(CanonicalJson::date('2026-11-02'))->toBe('2026-11-02')
        ->and(CanonicalJson::date(CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC')))->toBe('2026-11-02')
        ->and(CanonicalJson::date(null))->toBeNull();
});
