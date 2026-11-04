import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { ImageCarousel } from './image-carousel';

const images = [1, 2, 3].map((id) => ({ id, path: `/images/${id}.png`, url: `https://source.test/${id}`, position: id - 1 }));
function create(items = images) {
    const fixture = TestBed.createComponent(ImageCarousel);
    fixture.componentRef.setInput('images', items);
    fixture.componentRef.setInput('imageAlt', 'Товар');
    fixture.detectChanges();
    return fixture;
}

describe('ImageCarousel', () => {
    it('navigates with side buttons, keyboard and stops at the boundaries', () => {
        const fixture = create();
        const el: HTMLElement = fixture.nativeElement;
        const previous = el.querySelector<HTMLButtonElement>('.previous')!;
        const next = el.querySelector<HTMLButtonElement>('.next')!;
        expect(previous.disabled).toBe(true);
        next.click(); fixture.detectChanges();
        expect(el.querySelector('img')?.getAttribute('src')).toBe('/images/2.png');
        expect(el.textContent).toContain('Изображение 2 из 3');
        const region = el.querySelector('section')!;
        region.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        fixture.detectChanges();
        expect(next.disabled).toBe(true);
        expect(el.querySelector('img')?.getAttribute('alt')).toBe('Товар — изображение 3');
        previous.click(); fixture.detectChanges();
        expect(el.querySelector('img')?.getAttribute('src')).toBe('/images/2.png');
        region.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
        fixture.detectChanges();
        expect(previous.disabled).toBe(true);
    });
    it('handles empty/single arrays and resets selection when images change', () => {
        const fixture = create();
        fixture.componentInstance.next(); fixture.detectChanges();
        fixture.componentRef.setInput('images', [images[0]!]); fixture.detectChanges();
        const el: HTMLElement = fixture.nativeElement;
        expect(el.textContent).toContain('Изображение 1 из 1');
        expect([...el.querySelectorAll('button')].every((b) => b.disabled)).toBe(true);
        fixture.componentRef.setInput('images', []); fixture.detectChanges();
        expect(el.textContent).toContain('Изображений нет.');
        expect(el.querySelector('button')).toBeNull();
    });
    it('keeps a failed image fallback on return and never renders an unsafe source', () => {
        const fixture = create([images[0]!, { ...images[1]!, path: 'https://unsafe.test/image.png' }]);
        const el: HTMLElement = fixture.nativeElement;
        el.querySelector('img')!.dispatchEvent(new Event('error')); fixture.detectChanges();
        expect(el.querySelector('img')).toBeNull();
        fixture.componentInstance.next(); fixture.detectChanges();
        expect(el.querySelector('img')).toBeNull();
        expect(el.textContent).toContain('Изображение недоступно');
        fixture.componentInstance.previous(); fixture.detectChanges();
        expect(el.querySelector('img')).toBeNull();
        fixture.componentRef.setInput('images', [...images]); fixture.detectChanges();
        expect(el.querySelector('img')?.getAttribute('src')).toBe('/images/1.png');
    });
});
