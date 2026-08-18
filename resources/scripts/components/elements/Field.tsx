import React, { forwardRef, useState } from 'react';
import { Field as FormikField, FieldProps } from 'formik';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import tw from 'twin.macro';

interface OwnProps {
    name: string;
    light?: boolean;
    label?: string;
    description?: string;
    validate?: (value: any) => undefined | string | Promise<any>;
}

type Props = OwnProps & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'name'>;

const EyeIcon = ({ hidden }: { hidden: boolean }) => (
    <svg viewBox={'0 0 24 24'} aria-hidden={'true'} css={tw`w-5 h-5`} fill={'none'} stroke={'currentColor'} strokeWidth={'2'}>
        <path d={'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z'} />
        <circle cx={'12'} cy={'12'} r={'2.5'} />
        {hidden && <path d={'M3 3l18 18'} />}
    </svg>
);

const Field = forwardRef<HTMLInputElement, Props>(
    ({ id, name, light = false, label, description, validate, type, ...props }: Props, ref) => (
        <FormikField innerRef={ref} name={name} validate={validate}>
            {({ field, form: { errors, touched } }: FieldProps) => {
                const [visible, setVisible] = useState<boolean>(false);
                const isPassword = type === 'password';
                const inputType = isPassword && visible ? 'text' : type;

                return (
                    <div>
                        {label && (
                            <Label htmlFor={id} isLight={light}>
                                {label}
                            </Label>
                        )}
                        <div css={isPassword ? tw`relative` : undefined}>
                            <Input
                                id={id}
                                {...field}
                                {...props}
                                type={inputType}
                                isLight={light}
                                hasError={!!(touched[field.name] && errors[field.name])}
                                css={isPassword ? tw`pr-12` : undefined}
                            />
                            {isPassword && (
                                <button
                                    type={'button'}
                                    aria-label={visible ? 'Hide password' : 'Show password'}
                                    title={visible ? 'Hide password' : 'Show password'}
                                    onClick={() => setVisible((value: boolean) => !value)}
                                    css={tw`absolute right-3 top-1/2 -translate-y-1/2 text-neutral-500 hover:text-neutral-800 focus:outline-none`}
                                >
                                    <EyeIcon hidden={!visible} />
                                </button>
                            )}
                        </div>
                        {touched[field.name] && errors[field.name] ? (
                            <p className={'input-help error'}>
                                {(errors[field.name] as string).charAt(0).toUpperCase() +
                                    (errors[field.name] as string).slice(1)}
                            </p>
                        ) : description ? (
                            <p className={'input-help'}>{description}</p>
                        ) : null}
                    </div>
                );
            }}
        </FormikField>
    )
);
Field.displayName = 'Field';

export default Field;
