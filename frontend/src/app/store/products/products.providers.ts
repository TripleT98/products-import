import { makeEnvironmentProviders } from '@angular/core';
import { provideEffects } from '@ngrx/effects';
import { provideStore } from '@ngrx/store';
import { ProductsEffects } from '@store/products/products.effects';
import { productsReducer } from '@store/products/products.reducer';
import { productsFeatureKey } from '@store/products/products.state';

/** The application and integration tests use the same production registrations. */
export function provideProductsStore() {
    return makeEnvironmentProviders([
        provideStore(
            { [productsFeatureKey]: productsReducer },
            {
                runtimeChecks: {
                    strictStateImmutability: true,
                    strictActionImmutability: true,
                    strictStateSerializability: true,
                    strictActionSerializability: true,
                },
            },
        ),
        provideEffects(ProductsEffects),
    ]);
}
