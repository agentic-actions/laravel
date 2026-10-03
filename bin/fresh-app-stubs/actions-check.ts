import type { UrlMethodPair } from '@inertiajs/core';
import { createPost, type CreatePostInput, type CreatePostOutput } from './actions';

// A generated definition is structurally Inertia's UrlMethodPair, so useForm() and useHttp() accept it as it is.
export const pair: UrlMethodPair = createPost();

export const input: CreatePostInput = { title: 'Hi', body: 'Hello', excerpt: null };

export const title = (output: CreatePostOutput): string => output.title;
