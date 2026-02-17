<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
    },
];
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="relative flex h-full w-full flex-col rounded-xl bg-white bg-clip-border text-gray-700 shadow-md"
        >
            <div
                class="relative mx-4 mt-4 overflow-hidden rounded-none bg-white bg-clip-border text-gray-700"
            >
                <div class="mb-8 flex items-center justify-between gap-8">
                    <div>
                        <p
                            class="mt-1 block font-sans text-base leading-relaxed font-normal text-gray-700 antialiased"
                        >
                            See information about all employees
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                        <!-- <button
          class="select-none rounded-lg border border-gray-900 py-2 px-4 text-center align-middle font-sans text-xs font-bold uppercase text-gray-900 transition-all hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
          type="button">
          add Client
        </button> -->
                        <Dialog>
                            <form>
                                <DialogTrigger as-child>
                                    <button
                                        class="flex items-center gap-3 rounded-lg bg-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-white uppercase shadow-md shadow-gray-900/10 transition-all select-none hover:shadow-lg hover:shadow-gray-900/20 focus:opacity-[0.85] focus:shadow-none active:opacity-[0.85] active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                        type="button"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            stroke-width="2"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M6.25 6.375a4.125 4.125 0 118.25 0 4.125 4.125 0 01-8.25 0zM3.25 19.125a7.125 7.125 0 0114.25 0v.003l-.001.119a.75.75 0 01-.363.63 13.067 13.067 0 01-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 01-.364-.63l-.001-.122zM19.75 7.5a.75.75 0 00-1.5 0v2.25H16a.75.75 0 000 1.5h2.25v2.25a.75.75 0 001.5 0v-2.25H22a.75.75 0 000-1.5h-2.25V7.5z"
                                            ></path>
                                        </svg>
                                        Add user
                                    </button>
                                </DialogTrigger>
                                <DialogContent class="sm:max-w-[425px]">
                                    <DialogHeader>
                                        <DialogTitle>Add User</DialogTitle>
                                        <DialogDescription>
                                            Fill User details
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div class="grid gap-4">
                                        <div class="grid gap-3">
                                            <Label for="name-1"
                                                >First Name</Label
                                            >
                                            <Input
                                                id="name-1"
                                                name="name"
                                                default-value="Pedro Duarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1"
                                                >Last Name</Label
                                            >
                                            <Input
                                                id="name-1"
                                                name="name"
                                                default-value="Pedro Duarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1">Email</Label>
                                            <Input
                                                id="name-1"
                                                name="name"
                                                default-value="Pedro Duarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1">Email</Label>
                                            <Input
                                                id="name-1"
                                                name="name"
                                                default-value="Pedro Duarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="username-1"
                                                >Contact Number</Label
                                            >
                                            <Input
                                                id="username-1"
                                                name="username"
                                                default-value="@peduarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1">Role</Label>
                                            <Input
                                                id="name-1"
                                                name="name"
                                                default-value="Pedro Duarte"
                                            />
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1"
                                                >Select Client</Label
                                            >
                                            <select
                                                id="post-category"
                                                class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200"
                                            >
                                                <option value="" selected>
                                                    -- Choose category --
                                                </option>
                                            </select>
                                        </div>
                                        <div class="grid gap-3">
                                            <Label for="name-1"
                                                >Select Channel</Label
                                            >
                                            <select
                                                id="post-category"
                                                class="focus:ring-opacity-50 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200"
                                            >
                                                <option value="" selected>
                                                    -- Choose category --
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <DialogClose as-child>
                                            <Button variant="outline">
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button type="submit"> Save </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </form>
                        </Dialog>
                    </div>
                </div>

                <div
                    class="flex flex-col items-center justify-between gap-4 md:flex-row"
                >
                    <div class="block w-full overflow-hidden md:w-max">
                        <nav>
                            <ul
                                role="tablist"
                                class="bg-blue-gray-50 bg-opacity-60 relative flex flex-row rounded-lg p-1"
                            >
                                <li
                                    role="tab"
                                    class="text-blue-gray-900 relative flex h-full w-full cursor-pointer items-center justify-center bg-transparent px-2 py-1 text-center font-sans text-base leading-relaxed font-normal antialiased select-none"
                                    data-value="all"
                                >
                                    <div class="z-20 text-inherit">
                                        &nbsp;&nbsp;All&nbsp;&nbsp;
                                    </div>
                                    <div
                                        class="absolute inset-0 z-10 h-full rounded-md bg-white shadow"
                                    ></div>
                                </li>
                                <li
                                    role="tab"
                                    class="text-blue-gray-900 relative flex h-full w-full cursor-pointer items-center justify-center bg-transparent px-2 py-1 text-center font-sans text-base leading-relaxed font-normal antialiased select-none"
                                    data-value="monitored"
                                >
                                    <div class="z-20 text-inherit">
                                        &nbsp;&nbsp;Users&nbsp;&nbsp;
                                    </div>
                                </li>
                                <li
                                    role="tab"
                                    class="text-blue-gray-900 relative flex h-full w-full cursor-pointer items-center justify-center bg-transparent px-2 py-1 text-center font-sans text-base leading-relaxed font-normal antialiased select-none"
                                    data-value="unmonitored"
                                >
                                    <div class="z-20 text-inherit">
                                        &nbsp;&nbsp;Clients&nbsp;&nbsp;
                                    </div>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <div class="w-full md:w-72">
                        <div class="relative h-10 w-full min-w-[200px]">
                            <div
                                class="text-blue-gray-500 absolute top-2/4 right-3 grid h-5 w-5 -translate-y-2/4 place-items-center"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    aria-hidden="true"
                                    class="h-5 w-5"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
                                    ></path>
                                </svg>
                            </div>
                            <input
                                class="peer border-blue-gray-200 text-blue-gray-700 placeholder-shown:border-blue-gray-200 placeholder-shown:border-t-blue-gray-200 disabled:bg-blue-gray-50 h-full w-full rounded-[7px] border border-t-transparent bg-transparent px-3 py-2.5 !pr-9 font-sans text-sm font-normal outline outline-0 transition-all placeholder-shown:border focus:border-2 focus:border-gray-900 focus:border-t-transparent focus:outline-0 disabled:border-0"
                                placeholder=" "
                            />
                            <label
                                class="before:content[' '] after:content[' '] before:border-blue-gray-200 after:border-blue-gray-200 peer-placeholder-shown:text-blue-gray-500 peer-disabled:peer-placeholder-shown:text-blue-gray-500 pointer-events-none absolute -top-1.5 left-0 flex h-full w-full truncate !overflow-visible text-[11px] leading-tight font-normal text-gray-500 transition-all select-none peer-placeholder-shown:text-sm peer-placeholder-shown:leading-[3.75] peer-focus:text-[11px] peer-focus:leading-tight peer-focus:text-gray-900 peer-disabled:text-transparent before:pointer-events-none before:mt-[6.5px] before:mr-1 before:box-border before:block before:h-1.5 before:w-2.5 before:rounded-tl-md before:border-t before:border-l before:transition-all peer-placeholder-shown:before:border-transparent peer-focus:before:border-t-2 peer-focus:before:border-l-2 peer-focus:before:!border-gray-900 peer-disabled:before:border-transparent after:pointer-events-none after:mt-[6.5px] after:ml-1 after:box-border after:block after:h-1.5 after:w-2.5 after:flex-grow after:rounded-tr-md after:border-t after:border-r after:transition-all peer-placeholder-shown:after:border-transparent peer-focus:after:border-t-2 peer-focus:after:border-r-2 peer-focus:after:!border-gray-900 peer-disabled:after:border-transparent"
                            >
                                Search
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-scroll p-6 px-0">
                <table class="mt-4 w-full min-w-max table-auto text-left">
                    <thead>
                        <tr>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Member
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                        class="h-4 w-4"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"
                                        ></path>
                                    </svg>
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Roles
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                        class="h-4 w-4"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"
                                        ></path>
                                    </svg>
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Status
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                        class="h-4 w-4"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"
                                        ></path>
                                    </svg>
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                >
                                    Employed
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                        class="h-4 w-4"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"
                                        ></path>
                                    </svg>
                                </p>
                            </th>
                            <th
                                class="border-blue-gray-100 bg-blue-gray-50/50 hover:bg-blue-gray-50 cursor-pointer border-y p-4 transition-colors"
                            >
                                <p
                                    class="text-blue-gray-900 flex items-center justify-between gap-2 font-sans text-sm leading-none font-normal antialiased opacity-70"
                                ></p>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        src="https://demos.creative-tim.com/test/corporate-ui-dashboard/assets/img/team-3.jpg"
                                        alt="John Michael"
                                        class="relative inline-block h-9 w-9 !rounded-full object-cover object-center"
                                    />
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            John Michael
                                        </p>
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                        >
                                            john@creative-tim.com
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        Manager
                                    </p>
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                    >
                                        Organization
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="w-max">
                                    <div
                                        class="relative grid items-center rounded-md bg-green-500/20 px-2 py-1 font-sans text-xs font-bold whitespace-nowrap text-green-900 uppercase select-none"
                                    >
                                        <span class="">online</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    23/04/18
                                </p>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    class="relative h-10 max-h-[40px] w-10 max-w-[40px] rounded-lg text-center align-middle font-sans text-xs font-medium text-gray-900 uppercase transition-all select-none hover:bg-gray-900/10 active:bg-gray-900/20 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                    type="button"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transform"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M21.731 2.269a2.625 2.625 0 00-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 000-3.712zM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 00-1.32 2.214l-.8 2.685a.75.75 0 00.933.933l2.685-.8a5.25 5.25 0 002.214-1.32L19.513 8.2z"
                                            ></path>
                                        </svg>
                                    </span>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        src="https://demos.creative-tim.com/test/corporate-ui-dashboard/assets/img/team-2.jpg"
                                        alt="Alexa Liras"
                                        class="relative inline-block h-9 w-9 !rounded-full object-cover object-center"
                                    />
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            Alexa Liras
                                        </p>
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                        >
                                            alexa@creative-tim.com
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        Programator
                                    </p>
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                    >
                                        Developer
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="w-max">
                                    <div
                                        class="bg-blue-gray-500/20 text-blue-gray-900 relative grid items-center rounded-md px-2 py-1 font-sans text-xs font-bold whitespace-nowrap uppercase select-none"
                                    >
                                        <span class="">offline</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    23/04/18
                                </p>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    class="relative h-10 max-h-[40px] w-10 max-w-[40px] rounded-lg text-center align-middle font-sans text-xs font-medium text-gray-900 uppercase transition-all select-none hover:bg-gray-900/10 active:bg-gray-900/20 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                    type="button"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transform"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M21.731 2.269a2.625 2.625 0 00-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 000-3.712zM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 00-1.32 2.214l-.8 2.685a.75.75 0 00.933.933l2.685-.8a5.25 5.25 0 002.214-1.32L19.513 8.2z"
                                            ></path>
                                        </svg>
                                    </span>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        src="https://demos.creative-tim.com/test/corporate-ui-dashboard/assets/img/team-1.jpg"
                                        alt="Laurent Perrier"
                                        class="relative inline-block h-9 w-9 !rounded-full object-cover object-center"
                                    />
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            Laurent Perrier
                                        </p>
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                        >
                                            laurent@creative-tim.com
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        Executive
                                    </p>
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                    >
                                        Projects
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="w-max">
                                    <div
                                        class="bg-blue-gray-500/20 text-blue-gray-900 relative grid items-center rounded-md px-2 py-1 font-sans text-xs font-bold whitespace-nowrap uppercase select-none"
                                    >
                                        <span class="">offline</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    19/09/17
                                </p>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    class="relative h-10 max-h-[40px] w-10 max-w-[40px] rounded-lg text-center align-middle font-sans text-xs font-medium text-gray-900 uppercase transition-all select-none hover:bg-gray-900/10 active:bg-gray-900/20 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                    type="button"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transform"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M21.731 2.269a2.625 2.625 0 00-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 000-3.712zM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 00-1.32 2.214l-.8 2.685a.75.75 0 00.933.933l2.685-.8a5.25 5.25 0 002.214-1.32L19.513 8.2z"
                                            ></path>
                                        </svg>
                                    </span>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        src="https://demos.creative-tim.com/test/corporate-ui-dashboard/assets/img/team-4.jpg"
                                        alt="Michael Levi"
                                        class="relative inline-block h-9 w-9 !rounded-full object-cover object-center"
                                    />
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            Michael Levi
                                        </p>
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                        >
                                            michael@creative-tim.com
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        Programator
                                    </p>
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                    >
                                        Developer
                                    </p>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <div class="w-max">
                                    <div
                                        class="relative grid items-center rounded-md bg-green-500/20 px-2 py-1 font-sans text-xs font-bold whitespace-nowrap text-green-900 uppercase select-none"
                                    >
                                        <span class="">online</span>
                                    </div>
                                </div>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    24/12/08
                                </p>
                            </td>
                            <td class="border-blue-gray-50 border-b p-4">
                                <button
                                    class="relative h-10 max-h-[40px] w-10 max-w-[40px] rounded-lg text-center align-middle font-sans text-xs font-medium text-gray-900 uppercase transition-all select-none hover:bg-gray-900/10 active:bg-gray-900/20 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                    type="button"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transform"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M21.731 2.269a2.625 2.625 0 00-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 000-3.712zM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 00-1.32 2.214l-.8 2.685a.75.75 0 00.933.933l2.685-.8a5.25 5.25 0 002.214-1.32L19.513 8.2z"
                                            ></path>
                                        </svg>
                                    </span>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <img
                                        src="https://demos.creative-tim.com/test/corporate-ui-dashboard/assets/img/team-5.jpg"
                                        alt="Richard Gran"
                                        class="relative inline-block h-9 w-9 !rounded-full object-cover object-center"
                                    />
                                    <div class="flex flex-col">
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                        >
                                            Richard Gran
                                        </p>
                                        <p
                                            class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                        >
                                            richard@creative-tim.com
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                <div class="flex flex-col">
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                    >
                                        Manager
                                    </p>
                                    <p
                                        class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased opacity-70"
                                    >
                                        Executive
                                    </p>
                                </div>
                            </td>
                            <td class="p-4">
                                <div class="w-max">
                                    <div
                                        class="bg-blue-gray-500/20 text-blue-gray-900 relative grid items-center rounded-md px-2 py-1 font-sans text-xs font-bold whitespace-nowrap uppercase select-none"
                                    >
                                        <span class="">offline</span>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                <p
                                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                                >
                                    04/10/21
                                </p>
                            </td>
                            <td class="p-4">
                                <button
                                    class="relative h-10 max-h-[40px] w-10 max-w-[40px] rounded-lg text-center align-middle font-sans text-xs font-medium text-gray-900 uppercase transition-all select-none hover:bg-gray-900/10 active:bg-gray-900/20 disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                                    type="button"
                                >
                                    <span
                                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 transform"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            class="h-4 w-4"
                                        >
                                            <path
                                                d="M21.731 2.269a2.625 2.625 0 00-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 000-3.712zM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 00-1.32 2.214l-.8 2.685a.75.75 0 00.933.933l2.685-.8a5.25 5.25 0 002.214-1.32L19.513 8.2z"
                                            ></path>
                                        </svg>
                                    </span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="border-blue-gray-50 flex items-center justify-between border-t p-4"
            >
                <p
                    class="text-blue-gray-900 block font-sans text-sm leading-normal font-normal antialiased"
                >
                    Page 1 of 10
                </p>
                <div class="flex gap-2">
                    <button
                        class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                        type="button"
                    >
                        Previous
                    </button>
                    <button
                        class="rounded-lg border border-gray-900 px-4 py-2 text-center align-middle font-sans text-xs font-bold text-gray-900 uppercase transition-all select-none hover:opacity-75 focus:ring focus:ring-gray-300 active:opacity-[0.85] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none"
                        type="button"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
