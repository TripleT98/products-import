import { convertToParamMap } from '@angular/router';
import { describe, expect, it } from 'vitest';
import { filterError, readProductQuery } from '@features/products/product-list-query';

describe('List route/query validation', () => {
    it('defaults to the server page size and preserves decimal strings/zero without floating point conversion', () => {
        const parsed = readProductQuery(
            convertToParamMap({
                page: '2',
                limit: '20',
                name: 'чай & кофе',
                price_min: '0',
                price_max: '9999999999999999.99',
                importJobId: 'context',
            }),
        );
        expect(parsed.error).toBeNull();
        expect(parsed.query).toEqual({
            page: 2,
            limit: 20,
            name: 'чай & кофе',
            price_min: '0',
            price_max: '9999999999999999.99',
        });
        expect(readProductQuery(convertToParamMap({})).query).toEqual({ page: 1, limit: 20 });
    });

    it.each(['0', '-1', '1.5', '1e3', '', '2147483648'])(
        'rejects malformed/out-of-range page %s',
        (page) => {
            expect(readProductQuery(convertToParamMap({ page })).error).not.toBeNull();
        },
    );

    it('rejects repeated parameters and excessive page size', () => {
        expect(readProductQuery(convertToParamMap({ name: ['one', 'two'] })).error).not.toBeNull();
        expect(readProductQuery(convertToParamMap({ limit: '101' })).error).not.toBeNull();
    });

    it.each(['-1', '1e3', '1,23', '100.001', '10000000000000000.00', 'oops'])(
        'rejects invalid price %s',
        (price_min) => {
            expect(filterError({ name: '', price_min, price_max: '' })).not.toBeNull();
        },
    );

    it('compares inclusive bounds exactly, including large decimals and leading zeros', () => {
        expect(
            filterError({
                name: '',
                price_min: '9999999999999999.98',
                price_max: '9999999999999999.97',
            }),
        ).not.toBeNull();
        expect(filterError({ name: '', price_min: '000100.0', price_max: '100.00' })).toBeNull();
        expect(filterError({ name: '', price_min: '', price_max: '0' })).toBeNull();
    });
});
