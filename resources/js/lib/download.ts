import { http } from '@/lib/api';

/**
 * Downloads a file the API streams (CSV exports). The request carries the access token, so it cannot be a plain link.
 * Kept in one place so tests can replace it.
 */
export async function downloadFile(url: string, params: Record<string, string | undefined>, fallbackName: string): Promise<void> {
    const response = await http.get<Blob>(url, { params, responseType: 'blob' });
    const name = /filename="?([^";]+)"?/.exec(String(response.headers['content-disposition'] ?? ''))?.[1] ?? fallbackName;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(response.data);
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
}
