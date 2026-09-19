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

    /*
     * Minor is hundredths whatever the currency shows, so a tariff of 2.75
     * per unit survives being multiplied by a reading before anything
     * rounds it for a document.
     */
    it('stores hundredths even for a currency that displays none', function (): void {
        expect(Money::fromMajor('1234', 'KES')->minor)->toBe(123_400);
        expect(Money::fromMajor('12.34', 'USD')->minor)->toBe(1_234);
    });

    it('rounds a third decimal into the second rather than dropping it', function (): void {
        expect(Money::fromMajor('1.005', 'USD')->minor)->toBe(101);
        expect(Money::fromMajor('1.004', 'USD')->minor)->toBe(100);
    });

    /* A silent zero from a mistyped amount is an invoice nobody queries. */
    it('refuses what is not a plain decimal', function (string $raw): void {
        Money::fromMajor($raw, 'KES');
    })->with(['', 'abc', '1,000', '1.2.3', '1e5', ' '])->throws(InvalidArgumentException::class);

    it('keeps a negative amount negative', function (): void {
        expect(Money::fromMajor('-12.34', 'USD')->minor)->toBe(-1_234);
    });

    it('takes a fee quoted in hundredths of a percent', function (): void {
        expect(Money::of(100_000, 'KES')->shareOfBasisPoints(250)->minor)->toBe(2_500);
    });

    /* A meter reading or a part hour is not a whole number of units. */
    it('multiplies by a fractional quantity and rounds once at the end', function (): void {
        expect(Money::of(275, 'KES')->times(3.5)->minor)->toBe(963);
    });

    it('refuses a multiplication that leaves the supported range', function (): void {
        Money::of(1_000_000_000, 'KES')->times(1_000_000);
    })->throws(InvalidArgumentException::class);

    it('refuses an amount outside the range it can hold', function (): void {
        Money::assertWithinBounds(Money::maxMinor() + 1);
    })->throws(InvalidArgumentException::class);

    it('takes the range from config', function (): void {
        config(['noria.money.max_minor' => 5_000]);

        expect(Money::maxMinor())->toBe(5_000);
        expect(fn () => Money::assertWithinBounds(5_001))->toThrow(InvalidArgumentException::class);
    });
});

describe('showing an amount', function (): void {
    /*
     * Fraction digits are a display decision. The shilling shows none and
     * is still stored in hundredths.
     */
    it('shows a currency at its own number of decimals', function (): void {
        expect(Money::of(123_400, 'KES')->document())->toBe('1234');
        expect(Money::of(1_234, 'USD')->document())->toBe('12.34');
    });

    it('rounds to the decimals the currency shows', function (): void {
        expect(Money::of(123_450, 'KES')->document())->toBe('1235');
        expect(Money::of(123_449, 'KES')->document())->toBe('1234');
    });

    /* A tariff of 2.75 rendered as 3 is not the price. */
    it('shows a rate at two decimals whatever the currency does', function (): void {
        expect(Money::of(275, 'KES')->rate())->toBe('2.75');
        expect(Money::of(275, 'KES')->document())->toBe('3');
    });

    it('never writes a negative zero onto a document', function (): void {
        expect(Money::of(-40, 'KES')->document())->toBe('0');
    });

    it('takes the digits for a currency from config', function (): void {
        config(['noria.money.fraction_digits.KES' => 2]);

        expect(Money::of(123_400, 'KES')->document())->toBe('1234.00');
    });

    it('falls back to the default digits for a currency nobody listed', function (): void {
        config(['noria.money.digits' => 2]);

        expect(Money::fractionDigits('XOF'))->toBe(2);
    });

    it('never shows more than two decimals, because nothing stores them', function (): void {
        config(['noria.money.fraction_digits.KES' => 6]);

        expect(Money::fractionDigits('KES'))->toBe(2);
    });

    /* Number::currency uses non-breaking spaces, which no CSV survives. */
    it('formats with ordinary spaces', function (): void {
        expect(Money::of(123_400, 'KES')->format())->not->toContain("\u{00A0}");
    });

    it('shows a unit price with both decimals', function (): void {
        expect(Money::of(275, 'KES')->formatUnitPrice())->toContain('2.75');
    });

    it('shows the local symbol, not the ISO code', function (): void {
        expect(Money::of(123_450, 'KES')->format())->toContain('Ksh');
        expect(Money::of(123_450, 'TZS')->format())->toContain('TSh');
        expect(Money::of(123_450, 'UGX')->format())->toContain('USh');
    });

    /*
     * The bug this guards: ICU renders KES as "KES" under en and "Ksh"
     * under en_KE, so reading the locale from configuration means a deploy
     * that sets app.locale silently rewrites every amount in the product.
     */
    it('renders the same amount whatever the application locale is', function (): void {
        $amount = Money::of(123_450, 'KES');

        config(['app.locale' => 'en']);
        $under_en = $amount->format();

        config(['app.locale' => 'de_DE']);

        expect($amount->format())->toBe($under_en)->toContain('Ksh');
    });

    it('takes the locale a currency names, and lets config override it', function (): void {
        expect(Money::localeFor('KES'))->toBe('en_KE');
        expect(Money::localeFor('ZAR'))->toBe('en_ZA');

        // The euro is the currency whose code does not name a country.
        expect(Money::localeFor('EUR'))->toBe('en_IE');

        config(['noria.money.locales.KES' => 'sw_KE']);
        expect(Money::localeFor('KES'))->toBe('sw_KE');
    });

    it('still honours a locale passed in', function (): void {
        expect(Money::of(123_450, 'KES')->format('en'))->toContain('KES');
    });
});

describe('validating an amount', function (): void {
    it('bounds an amount already in minor units', function (): void {
        expect(Money::rules())->toBe(['integer', 'min:1', 'max:'.Money::maxMinor()]);
        expect(Money::rules(positive: false))->toBe(['integer', 'min:-'.Money::maxMinor(), 'max:'.Money::maxMinor()]);
    });

    it('accepts what a person could plausibly type', function (): void {
        expect(validator(['amount' => '12.50'], ['amount' => Money::majorRules('USD')])->passes())->toBeTrue();
    });

    it('refuses what they could not', function (string $raw): void {
        expect(validator(['amount' => $raw], ['amount' => Money::majorRules('USD')])->passes())->toBeFalse();
    })->with(['nonsense', '1,000', '1.2.3', '12abc']);

    /*
     * Laravel skips a closure rule on an empty value, so an optional
     * amount left blank passes. A field that must be filled says
     * 'required' itself, as it would for any other rule.
     */
    it('leaves an empty optional amount to the caller required rule', function (): void {
        expect(validator(['amount' => ''], ['amount' => Money::majorRules('USD')])->passes())->toBeTrue();
        expect(validator(['amount' => ''], ['amount' => ['required', ...Money::majorRules('USD')]])->passes())->toBeFalse();
    });

    it('refuses an amount under the floor the caller set', function (): void {
        expect(validator(['amount' => '0'], ['amount' => Money::majorRules('USD', minMinor: 100)])->passes())->toBeFalse();
    });

    it('accepts any amount when the caller set no floor', function (): void {
        expect(validator(['amount' => '0'], ['amount' => Money::majorRules('USD', minMinor: null)])->passes())->toBeTrue();
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

describe('rounding a quantity up to a step', function (): void {
    /*
     * A tariff slab is units times a rate, and units are fractional - a
     * meter reads 12.4. Making that an integer first truncates before the
     * rounding that was the point.
     */
    it('carries a fractional amount all the way into the rounding', function (): void {
        expect(Money::roundUpMinor(1234.4, 'KES'))->toBe(1300);
        expect(Money::roundUpMinor(1200.0, 'KES'))->toBe(1200);
    });

    it('rounds to the step the currency can actually show', function (): void {
        expect(Money::roundUpMinor(1234.4, 'USD'))->toBe(1235);
    });

    it('agrees with the instance form for a whole amount', function (): void {
        expect(Money::roundUpMinor(1010, 'KES'))->toBe(Money::of(1010, 'KES')->roundUpToStep()->minor);
    });

    it('leaves a figure already on a step where it is', function (): void {
        expect(Money::roundUpMinor(1500, 'KES'))->toBe(1500);
    });
});

describe('reading a csv already in memory', function (): void {
    /*
     * A product reading an import out of a request body has nothing to
     * give a path-based reader but a temp file it then has to clean up.
     */
    it('reads content the same way it reads a file', function (): void {
        $csv = "name,email\nAda,ada@example.com\n";

        expect(Reader::previewContent($csv)->headers)->toBe(['name', 'email']);
        expect(iterator_to_array(Reader::rowsContent($csv)))
            ->toBe([2 => ['name' => 'Ada', 'email' => 'ada@example.com']]);
    });

    it('sniffs a delimiter out of content without eating the header', function (): void {
        expect(Reader::previewContent("name;email\nAda;a@b.com\n")->delimiter)->toBe(';');
        expect(Reader::previewContent("name;email\nAda;a@b.com\n")->headers)->toBe(['name', 'email']);
    });

    it('counts the data rows, not the header', function (): void {
        expect(Reader::countContent("name\nAda\nGrace\n"))->toBe(2);
        expect(Reader::countContent("name\n"))->toBe(0);
    });

    it('reads past a byte order mark in content too', function (): void {
        expect(Reader::previewContent("\xEF\xBB\xBFname\nAda\n")->headers)->toBe(['name']);
    });
});

describe('an uploaded csv', function (): void {
    it('decodes what arrived base64 encoded', function (): void {
        expect(Reader::decode(base64_encode("name\nAda\n")))->toBe("name\nAda\n");
    });

    /* Bounded before it is decoded: decoding first has already allocated it. */
    it('refuses an encoded payload too large to be worth decoding', function (): void {
        Reader::decode(str_repeat('A', (int) ceil(Reader::maxBytes() * 4 / 3) + 2_048));
    })->throws(RuntimeException::class, 'too large');

    it('refuses content that decodes to more than the ceiling', function (): void {
        config(['noria.csv.max_bytes' => 16]);

        Reader::decode(base64_encode(str_repeat('a', 64)));
    })->throws(RuntimeException::class, 'too large');

    it('refuses something that is not base64 at all', function (): void {
        Reader::decode('not base64 !!!');
    })->throws(RuntimeException::class);

    it('bounds both shapes an upload arrives in', function (): void {
        $rules = Reader::uploadRules();

        expect($rules)->toHaveKeys(['csv', 'data_base64']);
        expect($rules['csv'])->toContain('required_without:data_base64');
        expect($rules['data_base64'])->toContain('required_without:csv');
    });

    it('takes the ceiling from config', function (): void {
        config(['noria.csv.max_bytes' => 1_024]);

        expect(Reader::maxBytes())->toBe(1_024);
        expect(Reader::uploadRules()['csv'])->toContain('max:1024');
    });
});
