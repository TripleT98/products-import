import { ChangeDetectionStrategy, Component, computed, input, linkedSignal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { ProductImage } from '@models/product';
import { localImagePath } from '@features/products/product-detail-state';

@Component({
    selector: 'app-image-carousel',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [MatButtonModule],
    templateUrl: './image-carousel.html',
    styleUrl: './image-carousel.css',
})
export class ImageCarousel {
    readonly images = input.required<readonly ProductImage[]>();
    readonly imageAlt = input('Изображение товара');
    readonly index = linkedSignal({ source: this.images, computation: () => 0 });
    readonly brokenImages = linkedSignal<readonly ProductImage[], ReadonlySet<string>>({
        source: this.images, computation: () => new Set<string>(),
    });
    readonly current = computed(() => this.images()[this.index()]);
    readonly imagePath = localImagePath;

    previous(): void {
        this.index.update((index) => Math.max(0, index - 1));
    }
    next(): void {
        this.index.update((index) => Math.min(Math.max(0, this.images().length - 1), index + 1));
    }
    imageFailed(path: string): void {
        this.brokenImages.update((paths) => new Set([...paths, path]));
    }
    onKeydown(event: KeyboardEvent): void {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            if (event.key === 'ArrowLeft') this.previous();
            else this.next();
        }
    }
}
