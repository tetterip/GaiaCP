{{-- Company page: draws the SVG logo as a PNG for emails where the server can't (no imagick); CompanySettings::storeEmailLogo() keeps it. --}}
<div
    wire:key="email-logo-{{ md5($url) }}"
    x-data
    x-init="
        const image = new Image();
        image.onload = () => {
            let height = {{ $height }};
            let width = Math.round(height * image.naturalWidth / image.naturalHeight);
            if (width > {{ $maxWidth }}) {
                width = {{ $maxWidth }};
                height = Math.max(1, Math.floor(width * image.naturalHeight / image.naturalWidth));
            }
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, width);
            canvas.height = height;
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            $wire.storeEmailLogo(canvas.toDataURL('image/png'));
        };
        image.src = @js($url);
    "
    hidden
></div>
