<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Csv\Reader;
use NoriaLabs\Platform\Csv\Writer;
use NoriaLabs\Platform\Http\Middleware\TrustProxies;
use NoriaLabs\Platform\Http\TrustedProxies;
use NoriaLabs\Platform\Money\Money;
use NoriaLabs\Platform\Tests\Fixtures\Priced;

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'platform-csv-').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

describe('money', function (): void {
    it('adds two amounts in the same currency', function (): void {
        expect(Money::of(1_000, 'KES')->plus(Money::of(250, 'KES'))->minor)->toBe(1_250);
    });

    it('refuses to add two currencies together', function (): void {
        Money::of(1_000, 'KES')->plus(Money::of(1_000, 'USD'));
    })->throws(InvalidArgumentException::class);

    it('reads what a person typed into minor units', function (): void {
        expect(Money::fromMajor('12.34', 'KES')->minor)->toBe(1_234);
    });

    it('takes a fee quoted in hundredths of a percent', function (): void {
        expect(Money::of(100_000, 'KES')->shareOfBasisPoints(250)->minor)->toBe(2_500);
    });

    it('rounds up to the next whole step for a minimum charge', function (): void {
        expect(Money::of(1_010, 'KES')->roundUpToStep(500)->minor)->toBe(1_500);
    });

    /*
     * An int past PHP_INT_MAX silently becomes a float, so a multiplication
     * that overflows returns a wrong amount rather than failing.
     */
    it('refuses a multiplication that would overflow instead of returning a float', function (): void {
        Money::of(1_000_000_000_000, 'KES')->times(PHP_INT_MAX);
    })->throws(RuntimeException::class);

    it('refuses an amount outside the range it can hold', function (): void {
        Money::of(Money::MAX_MINOR + 1, 'KES');
    })->throws(RuntimeException::class);

    it('takes the currency from config when the caller names none', function (): void {
        config(['noria.money.currency' => 'UGX']);

        expect(Money::zero()->currency)->toBe('UGX');
    });

    it('reads minor units from config, for a currency that has none', function (): void {
        config(['noria.money.minor_units' => 0]);

        expect(Money::fromMajor(1_500)->minor)->toBe(1_500);
    });
});

describe('reading a csv', function (): void {
    it('reads the headers and rows of an ordinary file', function (): void {
        $path = csvFile("name,email\nAda,ada@example.com\n");

        expect(Reader::preview($path)->headers)->toBe(['name', 'email']);
        expect(iterator_to_array(Reader::rows($path)))->toBe([2 => ['name' => 'Ada', 'email' => 'ada@example.com']]);
    });

    it('reads a file exported with semicolons', function (): void {
        $path = csvFile("name;email\nAda;ada@example.com\n");

        expect(Reader::sniff($path))->toBe(';');
        expect(Reader::preview($path)->headers)->toBe(['name', 'email']);
    });

    it('reads past the byte order mark Excel leaves at the front', function (): void {
        $path = csvFile("\xEF\xBB\xBFname,email\nAda,ada@example.com\n");

        expect(Reader::preview($path)->headers)->toBe(['name', 'email']);
    });

    it('keeps two columns of the same name apart', function (): void {
        $path = csvFile("phone,phone\n0700,0711\n");

        expect(Reader::preview($path)->headers)->toBe(['phone', 'phone (2)']);
    });

    it('names an unlabelled column after its position, because it still has data', function (): void {
        $path = csvFile("name,\nAda,extra\n");

        expect(Reader::preview($path)->headers)->toBe(['name', 'Column 2']);
    });

    it('skips a blank line rather than importing an empty record', function (): void {
        $path = csvFile("name\nAda\n\n   \nGrace\n");

        expect(iterator_to_array(Reader::rows($path)))->toBe([2 => ['name' => 'Ada'], 5 => ['name' => 'Grace']]);
    });

    it('numbers rows the way the spreadsheet does, counting the header', function (): void {
        $path = csvFile("name\nAda\nGrace\n");

        expect(array_keys(iterator_to_array(Reader::rows($path))))->toBe([2, 3]);
    });

    it('treats one column as the same column however it was capitalised', function (): void {
        expect(Reader::key('  Business  Name '))->toBe('business_name');
        expect(Reader::key('business name'))->toBe('business_name');
    });

    it('fills a short row rather than losing the columns after it', function (): void {
        $path = csvFile("name,email,phone\nAda,ada@example.com\n");

        expect(iterator_to_array(Reader::rows($path))[2])
            ->toBe(['name' => 'Ada', 'email' => 'ada@example.com', 'phone' => '']);
    });

    it('says so plainly when the file cannot be read', function (): void {
        Reader::preview('/does/not/exist.csv');
    })->throws(RuntimeException::class);
});

describe('writing a csv', function (): void {
    it('writes the headers and every row', function (): void {
        $csv = Writer::toString(['name', 'total'], [['Ada', 1_200], ['Grace', 900]]);

        expect($csv)->toContain("name,total\n");
        expect($csv)->toContain("Ada,1200\n");
    });

    it('leads with the mark Excel needs to read it as utf-8', function (): void {
        expect(Writer::toString(['name'], [['Ada']]))->toStartWith("\xEF\xBB\xBF");
    });

    /*
     * A cell opening with =, +, - or @ is a formula to a spreadsheet, so a
     * customer name is a way to run a command on whoever opens the export.
     */
    it('defuses a cell a spreadsheet would run as a formula', function (): void {
        expect(Writer::toString(['name'], [['=cmd|calc']]))->toContain("'=cmd|calc");
    });

    it('offers the file under a name a browser will accept', function (): void {
        $response = Writer::download('customer list', ['name'], [['Ada']]);

        expect($response->headers->get('Content-Disposition'))->toContain('customer-list.csv');
    });
});

describe('trusting a proxy', function (): void {
    it('trusts nothing when the host configured nothing', function (): void {
        expect(TrustedProxies::from(null))->toBeNull();
    });

    it('trusts the addresses the host listed', function (): void {
        expect(TrustedProxies::from('10.0.0.1, 10.0.0.2'))->toBe(['10.0.0.1', '10.0.0.2']);
    });

    it('trusts every hop only when told to in so many words', function (): void {
        expect(TrustedProxies::from('*'))->toBe('*');
    });
});

describe('money on a model', function (): void {
    beforeEach(function (): void {
        Schema::create('priced', function ($table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('total')->nullable();
            $table->string('currency', 8)->nullable();
        });
    });

    it('reads an integer column back as an amount', function (): void {
        Priced::query()->create(['id' => '01a0b000-0000-7000-8000-000000000001', 'total' => 1_250, 'currency' => 'KES']);

        $amount = Priced::query()->sole()->total;

        expect($amount)->toBeInstanceOf(Money::class);
        expect($amount->minor)->toBe(1_250);
        expect($amount->currency)->toBe('KES');
    });

    it('writes an amount back as the integer it is', function (): void {
        Priced::query()->create([
            'id' => '01a0b000-0000-7000-8000-000000000002',
            'total' => Money::of(990, 'KES'),
            'currency' => 'KES',
        ]);

        expect(Priced::query()->sole()->getRawOriginal('total'))->toBe(990);
    });

    /* A fixed fallback would mislabel every amount belonging to elsewhere. */
    it('takes the currency from the row beside it', function (): void {
        Priced::query()->create(['id' => '01a0b000-0000-7000-8000-000000000003', 'total' => 500, 'currency' => 'UGX']);

        expect(Priced::query()->sole()->total->currency)->toBe('UGX');
    });

    it('falls back to the configured currency only when the row says nothing', function (): void {
        config(['noria.money.currency' => 'TZS']);

        Priced::query()->create(['id' => '01a0b000-0000-7000-8000-000000000004', 'total' => 500]);

        expect(Priced::query()->sole()->total->currency)->toBe('TZS');
    });

    it('leaves an empty column empty rather than calling it zero', function (): void {
        Priced::query()->create(['id' => '01a0b000-0000-7000-8000-000000000005']);

        expect(Priced::query()->sole()->total)->toBeNull();
    });
});

describe('trusting a proxy in the middleware', function (): void {
    function throughProxy(): string
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $request->headers->set('X-Forwarded-For', '203.0.113.9');

        (new TrustProxies)->handle($request, fn () => new Response);

        return (string) $request->ip();
    }

    /*
     * Without this every per-address limit counts the whole platform as
     * one caller, and the trail records the balancer on every row.
     */
    it('believes the caller a trusted hop forwarded', function (): void {
        config(['noria.http.trusted_proxies' => '10.0.0.1,10.0.0.2']);

        expect(throughProxy())->toBe('203.0.113.9');
    });

    it('believes nothing forwarded by a hop it was not told about', function (): void {
        config(['noria.http.trusted_proxies' => '192.0.2.1']);

        expect(throughProxy())->toBe('10.0.0.1');
    });

    it('believes nothing at all when the host configured nothing', function (): void {
        config(['noria.http.trusted_proxies' => null]);

        expect(throughProxy())->toBe('10.0.0.1');
    });

    it('believes every hop only when told to in so many words', function (): void {
        config(['noria.http.trusted_proxies' => '*']);

        expect(throughProxy())->toBe('203.0.113.9');
    });
});
