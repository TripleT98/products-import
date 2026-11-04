import { Routes } from '@angular/router';
import { authGuard } from './core/auth/auth.guard';
export const routes: Routes = [
    { path: '', pathMatch: 'full', redirectTo: 'products' },
    {
        path: 'login',
        title: 'Вход',
        loadComponent: () =>
            import('@features/auth/login-page/login-page').then((m) => m.LoginPage),
    },
    {
        path: 'import',
        canActivate: [authGuard],
        title: 'Импорт товаров',
        loadComponent: () =>
            import('@features/imports/import-page/import-page').then((m) => m.ImportPage),
    },
    {
        path: 'import/:jobId',
        canActivate: [authGuard],
        title: 'Результат импорта',
        loadComponent: () =>
            import('@features/imports/import-job-page/import-job-page').then(
                (m) => m.ImportJobPage,
            ),
    },
    {
        path: 'products',
        canActivate: [authGuard],
        title: 'Товары',
        loadComponent: () =>
            import('@features/products/product-list-page/product-list-page').then(
                (m) => m.ProductListPage,
            ),
    },
    {
        path: 'products/:id',
        canActivate: [authGuard],
        title: 'Карточка товара',
        loadComponent: () =>
            import('@features/products/product-detail-page/product-detail-page').then(
                (m) => m.ProductDetailPage,
            ),
    },
    {
        path: '**',
        title: 'Страница не найдена',
        loadComponent: () => import('./workspace/workspace').then((m) => m.Workspace),
    },
];
