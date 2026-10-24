import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type ListParams } from '@/lib/api';

export interface EmailTemplate {
    id: string;
    key: string;
    name: string;
    description: string | null;
    subject: string;
    enabled: boolean;
    version: number;
    updated_at: string;
    /** Detail only. */
    html_body?: string;
    text_body?: string;
    /** Placeholder => what it means. Detail only. */
    variables?: Record<string, string>;
    /** Placeholder => sample value used by preview and test-send. Detail only. */
    samples?: Record<string, string>;
}

export interface EmailTemplateVersion {
    id: string;
    version: number;
    subject: string;
    html_body: string;
    text_body: string;
    author: string | null;
    created_at: string;
}

export interface TemplateContent {
    subject: string;
    html_body: string;
    text_body: string;
}

export interface TemplateUpdate extends TemplateContent {
    enabled: boolean;
}

export interface RenderedEmail {
    subject: string;
    html: string;
    text: string;
}

export type EmailStatus = 'queued' | 'sent' | 'failed' | 'skipped';

export const emailStatusLabels: Record<EmailStatus, string> = { queued: 'Queued', sent: 'Sent', failed: 'Failed', skipped: 'Skipped' };

export interface EmailLog {
    id: string;
    template_key: string;
    provider_id: string | null;
    to_email: string;
    subject: string;
    status: EmailStatus;
    error: string | null;
    attempts: number;
    sent_at: string | null;
    created_at: string;
    html_body?: string;
    text_body?: string;
}

const templatesKey = ['admin', 'email-templates'] as const;
const logsKey = ['admin', 'email-logs'] as const;

export const useEmailTemplates = (params: ListParams) =>
    useQuery({
        queryKey: [...templatesKey, 'list', params],
        queryFn: () => api.list<EmailTemplate>('/admin/email-templates', params),
        placeholderData: keepPreviousData,
    });

export const useEmailTemplate = (id: string | undefined) =>
    useQuery({ queryKey: [...templatesKey, 'detail', id], queryFn: () => api.get<EmailTemplate>(`/admin/email-templates/${id}`), enabled: Boolean(id) });

export const useTemplateVersions = (id: string | undefined) =>
    useQuery({
        queryKey: [...templatesKey, 'versions', id],
        queryFn: () => api.get<EmailTemplateVersion[]>(`/admin/email-templates/${id}/versions`),
        enabled: Boolean(id),
    });

export function useSaveTemplate(id: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (body: TemplateUpdate) => api.put<EmailTemplate>(`/admin/email-templates/${id}`, body),
        onSuccess: () => client.invalidateQueries({ queryKey: templatesKey }),
    });
}

export function useRestoreVersion(id: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (version: number) => api.post<EmailTemplate>(`/admin/email-templates/${id}/versions/${version}/restore`),
        onSuccess: () => client.invalidateQueries({ queryKey: templatesKey }),
    });
}

export const usePreviewTemplate = (id: string) =>
    useMutation({ mutationFn: (body: TemplateContent) => api.post<RenderedEmail>(`/admin/email-templates/${id}/preview`, body) });

export function useTestTemplate(id: string) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (to: string) => api.post<EmailLog>(`/admin/email-templates/${id}/test`, { to }),
        onSuccess: () => client.invalidateQueries({ queryKey: logsKey }),
    });
}

export const useEmailLogs = (params: ListParams) =>
    useQuery({ queryKey: [...logsKey, 'list', params], queryFn: () => api.list<EmailLog>('/admin/email-logs', params), placeholderData: keepPreviousData });

export const useEmailLog = (id: string | null) =>
    useQuery({ queryKey: [...logsKey, 'detail', id], queryFn: () => api.get<EmailLog>(`/admin/email-logs/${id}`), enabled: id !== null });

export function useResendEmail() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (id: string) => api.post<EmailLog>(`/admin/email-logs/${id}/resend`),
        onSuccess: () => client.invalidateQueries({ queryKey: logsKey }),
    });
}

export const useSendTestMail = () => useMutation({ mutationFn: (to: string) => api.post<{ sent: boolean }>('/admin/mail/test', { to }) });
