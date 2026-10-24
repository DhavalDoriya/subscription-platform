import { ImagePlus, Images, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { useMediaMutations } from '@/areas/provider/services/media';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { ApiError } from '@/lib/api';
import { MediaLibraryDialog } from './MediaLibraryDialog';

interface Props {
    label: string;
    value: string;
    onChange: (url: string) => void;
    error?: string;
    hint?: string;
}

/** An image URL the provider can type, upload (media library) or pick from earlier uploads. The value stays a plain URL. */
export function ImageField({ label, value, onChange, error, hint }: Props) {
    const id = useId();
    const fileInput = useRef<HTMLInputElement>(null);
    const { upload } = useMediaMutations();
    const [uploadError, setUploadError] = useState<string>();
    const [libraryOpen, setLibraryOpen] = useState(false);

    async function onFile(file: File | undefined) {
        if (!file) return;
        setUploadError(undefined);
        try {
            onChange((await upload.mutateAsync(file)).url);
        } catch (e) {
            setUploadError(e instanceof ApiError ? (e.firstError('file') ?? e.message) : 'Upload failed. Try again.');
        } finally {
            if (fileInput.current) fileInput.current.value = '';
        }
    }

    return (
        <div className="grid gap-2">
            <TextField id={id} label={label} type="url" value={value} error={error} hint={hint} onChange={(e) => onChange(e.target.value)} />
            <div className="flex flex-wrap items-center gap-2">
                <input
                    ref={fileInput}
                    type="file"
                    hidden
                    aria-label={`${label} file`}
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    onChange={(e) => onFile(e.target.files?.[0])}
                />
                <Button type="button" size="sm" variant="outline" loading={upload.isPending} onClick={() => fileInput.current?.click()}>
                    <ImagePlus aria-hidden /> Upload image
                </Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setLibraryOpen(true)}>
                    <Images aria-hidden /> Choose from library
                </Button>
                {value && (
                    <Button type="button" size="sm" variant="ghost" onClick={() => onChange('')}>
                        <X aria-hidden /> Remove image
                    </Button>
                )}
            </div>
            {uploadError && <Alert>{uploadError}</Alert>}
            {value && <img src={value} alt={`${label} preview`} className="max-h-32 w-fit max-w-full rounded-md border object-contain" />}
            <MediaLibraryDialog
                open={libraryOpen}
                onOpenChange={setLibraryOpen}
                onSelect={(media) => {
                    onChange(media.url);
                    setLibraryOpen(false);
                }}
            />
        </div>
    );
}
