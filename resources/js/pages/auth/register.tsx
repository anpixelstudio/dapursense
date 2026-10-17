import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
    invitation: { organization: string; email: string } | null;
};

export default function Register({ passwordRules, invitation }: Props) {
    return (
        <>
            <Head title="Daftar" />
            {invitation && (
                <p
                    className="rounded-lg border bg-muted/40 px-3 py-2 text-sm"
                    data-test="register-invitation"
                >
                    Anda diundang bergabung ke{' '}
                    <span className="font-medium">
                        {invitation.organization}
                    </span>
                    . Daftar dengan email {invitation.email} untuk menerima
                    undangan; organisasi baru tidak akan dibuat.
                </p>
            )}
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Nama lengkap"
                                />
                                <InputError message={errors.name} />
                            </div>

                            {!invitation && (
                                <div className="grid gap-2">
                                    <Label htmlFor="organization_name">
                                        Nama organisasi
                                    </Label>
                                    <Input
                                        id="organization_name"
                                        type="text"
                                        required
                                        tabIndex={2}
                                        name="organization_name"
                                        placeholder="Contoh: Hotel Anpixel Jakarta"
                                    />
                                    <InputError
                                        message={errors.organization_name}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    name="email"
                                    defaultValue={invitation?.email}
                                    placeholder="email@contoh.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Konfirmasi password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Ulangi password"
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="terms"
                                        name="terms"
                                        value="1"
                                        tabIndex={6}
                                    />
                                    <Label
                                        htmlFor="terms"
                                        className="text-sm leading-snug font-normal"
                                    >
                                        Saya menyetujui penggunaan sistem untuk
                                        analisis aktivitas dapur tanpa
                                        pengenalan wajah.
                                    </Label>
                                </div>
                                <InputError message={errors.terms} />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={7}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Buat akun
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Sudah punya akun?{' '}
                            <TextLink href={login()} tabIndex={8}>
                                Masuk
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Buat akun',
    description:
        'Pendaftar menjadi Owner organisasi baru, kecuali datang dari link undangan.',
};
