import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Field = {
    key: string;
    label: string;
    help: string;
    secret: boolean;
    set: boolean;
    value: string | null;
};

export default function ControlPlaneSettings({ fields }: { fields: Field[] }) {
    const { data, setData, put, processing, errors } = useForm<
        Record<string, string | string[]>
    >({
        ...Object.fromEntries(
            fields.map((f) => [f.key, f.secret ? '' : (f.value ?? '')]),
        ),
        clear: [],
    });

    const clear = (data.clear as string[]) ?? [];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/control-plane/settings', {
            preserveScroll: true,
            onSuccess: () => {
                const reset: Record<string, string> = {};
                fields
                    .filter((f) => f.secret)
                    .forEach((f) => (reset[f.key] = ''));
                setData((d) => ({ ...d, ...reset, clear: [] }));
            },
        });
    };

    return (
        <>
            <Head title="Settings" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-semibold">
                        Settings
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Shared by every client. Tokens are stored encrypted and
                        never shown again; leave a token field empty to keep the
                        one already saved.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>GitHub, registry and certificates</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-6">
                        {fields.map((field) => (
                            <div key={field.key} className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <Label htmlFor={field.key}>
                                        {field.label}
                                    </Label>
                                    {field.secret &&
                                        (field.set &&
                                        !clear.includes(field.key) ? (
                                            <Badge variant="secondary">
                                                saved
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                not set
                                            </Badge>
                                        ))}
                                </div>
                                <div className="flex gap-2">
                                    <Input
                                        id={field.key}
                                        type={
                                            field.secret ? 'password' : 'text'
                                        }
                                        autoComplete="off"
                                        value={
                                            (data[field.key] as string) ?? ''
                                        }
                                        placeholder={
                                            field.secret && field.set
                                                ? 'Leave empty to keep the saved value'
                                                : ''
                                        }
                                        onChange={(e) =>
                                            setData(field.key, e.target.value)
                                        }
                                    />
                                    {field.secret && field.set && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setData(
                                                    'clear',
                                                    clear.includes(field.key)
                                                        ? clear.filter(
                                                              (k) =>
                                                                  k !==
                                                                  field.key,
                                                          )
                                                        : [...clear, field.key],
                                                )
                                            }
                                        >
                                            {clear.includes(field.key)
                                                ? 'Keep'
                                                : 'Remove'}
                                        </Button>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {field.help}
                                </p>
                                <InputError message={errors[field.key]} />
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <div>
                    <Button type="submit" disabled={processing}>
                        Save
                    </Button>
                </div>
            </form>
        </>
    );
}

ControlPlaneSettings.layout = {
    breadcrumbs: [{ title: 'Settings', href: '/control-plane/settings' }],
};
