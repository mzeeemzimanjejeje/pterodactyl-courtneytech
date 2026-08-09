import React, { forwardRef } from 'react';
import { Form } from 'formik';
import styled from 'styled-components/macro';
import { breakpoint } from '@/theme';
import FlashMessageRender from '@/components/FlashMessageRender';
import tw from 'twin.macro';

type Props = React.DetailedHTMLProps<React.FormHTMLAttributes<HTMLFormElement>, HTMLFormElement> & {
    title?: string;
};

const Container = styled.div`
    ${tw`w-full mx-auto px-4`};

    ${breakpoint('sm')`
        ${tw`w-4/5`}
        max-width: 420px;
    `};

    ${breakpoint('md')`
        max-width: 460px;
    `};
`;

export default forwardRef<HTMLFormElement, Props>(({ title, ...props }, ref) => (
    <Container>
        <div css={tw`flex flex-col items-center mb-6 select-none`}>
            <img src={'/assets/svgs/pterodactyl.svg'} css={tw`block w-14 md:w-16 mb-3`} />
            {title && <h2 css={tw`text-2xl text-center text-neutral-100 font-medium`}>{title}</h2>}
        </div>
        <FlashMessageRender css={tw`mb-2 px-1`} />
        <Form {...props} ref={ref}>
            <div css={tw`w-full bg-white shadow-lg rounded-lg p-6 md:p-8 mx-1`}>{props.children}</div>
        </Form>
        <p css={tw`text-center text-neutral-500 text-xs mt-4`}>
            &copy; 2015 - {new Date().getFullYear()}&nbsp;
            <a
                rel={'noopener nofollow noreferrer'}
                href={'https://pterodactyl.io'}
                target={'_blank'}
                css={tw`no-underline text-neutral-500 hover:text-neutral-300`}
            >
                Pterodactyl Software
            </a>
        </p>
    </Container>
));
