import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { errorMessages } from '@/lib/exit';

type Target = { url: string; method: string };

/**
 * One-click actions on the exit page (tick an item, recalculate, remove a line, approve): runs the request, remembers which
 * one is running (`busy`) and keeps the server's refusal (`error`) so the panel can show it. Nothing is hidden: an action the
 * server refuses leaves its message on screen until the next action or until it is dismissed.
 */
export function useExitAction() {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string[]>([]);

    const run = (
        key: string,
        target: Target,
        options: {
            data?: Record<string, unknown>;
            onSuccess?: () => void;
            onError?: (messages: string[]) => void;
        } = {},
    ) => {
        router.visit(target.url, {
            method: target.method as 'post',
            data: options.data as Record<
                string,
                string | number | boolean | null
            >,
            preserveScroll: true,
            onStart: () => {
                setBusy(key);
                setError([]);
            },
            onSuccess: () => options.onSuccess?.(),
            onError: (errors) => {
                const messages = errorMessages(errors);
                const shown =
                    messages.length > 0
                        ? messages
                        : ['That did not work. Try again.'];

                setError(shown);
                options.onError?.(shown);
            },
            onFinish: () => setBusy(null),
        });
    };

    return { busy, error, run, clear: () => setError([]) };
}

/** The refusal of a one-click action, above the panel it belongs to. Nothing renders when there is none. */
export function ActionError({ messages }: { messages: string[] }) {
    if (messages.length === 0) {
        return null;
    }

    return (
        <Alert variant="destructive" role="alert">
            <TriangleAlert />
            <AlertDescription>
                {messages.map((message) => (
                    <span key={message} className="block">
                        {message}
                    </span>
                ))}
            </AlertDescription>
        </Alert>
    );
}
