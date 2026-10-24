import { useState } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/TextField';
import { useFormSubmit } from '@/lib/useFormSubmit';
import { useSendTestMail } from './services/email';

/** FR-E5 "send test email": tries the saved mail settings and says whether the mail server accepted the message. */
export function MailTestPanel() {
    const [to, setTo] = useState('');
    const [sentTo, setSentTo] = useState<string>();
    const send = useSendTestMail();
    const form = useFormSubmit(
        () => send.mutateAsync(to),
        () => setSentTo(to),
    );

    return (
        <section aria-label="Test email" className="mt-6 rounded-lg border bg-card p-6">
            <h2 className="text-lg font-semibold">Test email</h2>
            <p className="mb-5 text-sm text-muted-foreground">Save your mail settings first, then send a test message to check them.</p>
            <form onSubmit={form.submit} noValidate className="grid max-w-xl gap-4">
                {form.formError && <Alert>{form.formError}</Alert>}
                {sentTo && !form.formError && <p className="text-sm text-success">{`Test email sent to ${sentTo}.`}</p>}
                <TextField label="Send a test email to" type="email" value={to} onChange={(e) => setTo(e.target.value)} error={form.fieldError('to')} />
                <div>
                    <Button type="submit" variant="outline" loading={form.pending}>
                        Send test email
                    </Button>
                </div>
            </form>
        </section>
    );
}
