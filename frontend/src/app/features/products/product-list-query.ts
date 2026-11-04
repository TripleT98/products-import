import { ProductErrorMessage } from '@models/product-error-message.enum';
import { ParamMap } from '@angular/router';
import { ProductQuery } from '@models/product';

export interface ProductFilters {
    name: string;
    price_min: string;
    price_max: string;
}

function price(value: string): string | null {
    if (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value)) return null;
    const [integer, fraction = ''] = value.split('.');
    const normalized = integer.replace(/^0+/, '') || '0';
    return normalized.length > 16
        ? null
        : normalized.padStart(16, '0') + '.' + fraction.padEnd(2, '0');
}

export function filterError(filters: ProductFilters): string | null {
    const min = filters.price_min === '' ? null : price(filters.price_min);
    const max = filters.price_max === '' ? null : price(filters.price_max);
    if ((filters.price_min !== '' && min === null) || (filters.price_max !== '' && max === null))
        return ProductErrorMessage.InvalidPrice;
    return min !== null && max !== null && min > max
        ? ProductErrorMessage.InvalidPriceRange
        : null;
}

export function readProductQuery(params: ParamMap): {
    filters: ProductFilters;
    query: ProductQuery;
    error: string | null;
} {
    const filters = {
        name: params.get('name') ?? '',
        price_min: params.get('price_min') ?? '',
        price_max: params.get('price_max') ?? '',
    };
    const page = params.get('page') ?? '1';
    const limit = params.get('limit') ?? '20';
    const query: ProductQuery = { page: Number(page), limit: Number(limit) };
    if (filters.name !== '') query.name = filters.name;
    if (filters.price_min !== '') query.price_min = filters.price_min;
    if (filters.price_max !== '') query.price_max = filters.price_max;
    const invalid =
        ['page', 'limit', 'name', 'price_min', 'price_max'].some(
            (key) => params.getAll(key).length > 1,
        ) ||
        !/^[0-9]+$/.test(page) ||
        !/^[0-9]+$/.test(limit) ||
        !Number.isSafeInteger(query.page) ||
        (query.page ?? 0) < 1 ||
        (query.page ?? 0) > 2147483647 ||
        !Number.isSafeInteger(query.limit) ||
        (query.limit ?? 0) < 1 ||
        (query.limit ?? 0) > 100;
    return {
        filters,
        query,
        error: invalid ? ProductErrorMessage.InvalidPageQuery : filterError(filters),
    };
}
