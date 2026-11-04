import { Page, PageQuery } from '@models/pagination';

/** DECIMAL values and UTC timestamps remain JSON strings, without Number/Date conversion. */
export interface Product {
    id: number;
    externalCode: string;
    name: string;
    description: string;
    price: string;
    purchasePrice: string;
    discount: string;
    createdAt: string;
    updatedAt: string;
}

export interface ProductAttribute {
    id: number;
    key: string;
    value: string;
}

export interface ProductImage {
    id: number;
    url: string;
    path: string;
    position: number;
}

export interface ProductDetail extends Product {
    attributes: ProductAttribute[];
    images: ProductImage[];
}

export interface ProductQuery extends PageQuery {
    name?: string;
    price_min?: string;
    price_max?: string;
}

export type ProductPage = Page<Product>;
