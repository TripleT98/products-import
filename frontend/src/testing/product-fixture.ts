import { ProductDetail } from '@models/product';

export function productFixture(id = 42): ProductDetail {
    return {
        id,
        externalCode: '000042',
        name: 'Товар ' + id,
        description: '<script>unsafe()</script>\nКириллица',
        price: '9999999999999999.99',
        purchasePrice: '0.00',
        discount: '-33.33',
        createdAt: '2026-11-03T00:00:00Z',
        updatedAt: '2026-11-03T01:00:00Z',
        attributes: [{ id: 1, key: 'Размер', value: '0' }],
        images: [
            {
                id: 1,
                url: 'https://external.test/source.png',
                path: '/images/fixture.png',
                position: 0,
            },
        ],
    };
}
