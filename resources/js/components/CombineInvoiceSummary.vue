<template>
  <div class="mb-6">
    <CardSectionHeader>
      {{ __('Summary') }}
    </CardSectionHeader>
    <HelpText class="text-sm mt-1">
      {{ __('Below is the summary of the invoice that will be created from combining the following invoices.') }}
    </HelpText>
  </div>

  <dl class="sm:divide-y sm:divide-gray-200 dark:sm:divide-gray-500">
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Invoices') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        <div
          v-for="item in selection"
          :key="item.uuid"
          class="flex items-start justify-between py-1"
        >
          <div>
            {{ item.title }}: <span class="text-gray-500 dark:text-gray-400">{{ item.invoice_number }}</span>
            <p>{{ item.student.full_name }}</p>
          </div>
          <div>
            {{ item.amount_due_formatted }}
          </div>
        </div>
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Title') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        {{ invoice.title }}
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Description') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        {{ invoice.description }}
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Availability date') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        <span v-if="invoice.available_at" class="flex items-end">
          <span>{{ displayDate(invoice.available_at, 'MMMM D, YYYY H:mm') }}</span>
          <span class="inline-flex ml-3">
            <button class="text-gray-500 dark:text-gray-300 hover:underline focus:outline-none" type="button" @click.prevent="invoice.available_at = null">
              {{ __('Remove') }}
            </button>
          </span>
        </span>
        <span v-else>
          {{ __('No due date.') }}
        </span>
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Due date') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        <span v-if="invoice.due_at" class="flex items-end">
          <span>{{ displayDate(invoice.due_at, 'MMMM D, YYYY H:mm') }}</span>
          <span class="inline-flex ml-3">
            <button class="text-gray-500 dark:text-gray-300 hover:underline focus:outline-none" type="button" @click.prevent="invoice.due_at = null">
              {{ __('Remove') }}
            </button>
          </span>
        </span>
        <span v-else>
          {{ __('No due date.') }}
        </span>
      </dd>
    </div>
    <div v-if="$page.props.school.guardian_notifications_enabled" class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Notification') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        <span v-if="invoice.notify">
          {{ __('Contacts will be notified in 15 minutes, unless cancelled.') }}
        </span>
        <span v-else>
          {{ __('Manually notify.') }}
        </span>
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium text-gray-500 dark:text-gray-300">
        {{ __('Payment schedules') }}
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2">
        <div class="space-y-2 divide-y divide-gray-200 dark:divide-gray-500">
          <div v-if="invoice.payment_schedules.length === 0">{{ __('None') }}</div>
          <div
            v-for="(item, index) in invoice.payment_schedules"
            :key="item.id"
            class="space-y-1"
            :class="{
              'pt-2': index > 0
            }"
          >
            <div class="flex justify-between">
              <div class="font-bold">
                {{ __(':count payments', { count: item.terms.length }) }}
              </div>
              <div class="font-bold">
                {{ displayCurrency(getScheduleTotal(item)) }}
              </div>
            </div>

            <div
              v-for="term in item.terms"
              class="flex justify-between"
            >
              <div>
                {{ term.due_at ? displayDate(term.due_at, 'MMMM D, YYYY H:mm') : __('No due date') }}
              </div>
              <div>
                {{ displayCurrency(term.amount) }}
              </div>
            </div>
          </div>
        </div>
      </dd>
    </div>
    <div class="py-4 sm:py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
      <dt class="text-sm font-medium">
        <strong>{{ __('Invoice total') }}</strong>
      </dt>
      <dd class="mt-1 text-sm sm:mt-0 sm:col-span-2 text-right">
        <strong>{{ totalDue }}</strong>
      </dd>
    </div>
  </dl>
</template>

<script>
import { computed } from 'vue'
import CardHeader from '@/components/CardHeader.vue'
import HelpText from '@/components/HelpText.vue'
import Label from '@/components/forms/Label.vue'
import displaysCurrency from '@/composition/displaysCurrency.js'
import Button from '@/components/Button.vue'
import CardSectionHeader from '@/components/CardSectionHeader.vue'
import displaysDate from '@/composition/displaysDate.js'
import invoicePaymentScheduleForm from '@/composition/invoicePaymentScheduleForm.js'
import CardWrapper from '@/components/CardWrapper.vue'
import CardPadding from '@/components/CardPadding.vue'
import useSchool from '@/composition/useSchool.js'

export default {
  components: {
    CardPadding,
    CardWrapper,
    CardSectionHeader,
    Button,
    HelpText,
    CardHeader,
    Label,
  },
  props: {
    invoice: {
      type: Object,
      required: true,
    },
    total: Number,
    selection: Array,
  },
  emits: ['close'],

  setup (props) {
    const { school } = useSchool()

    const { timezone, displayDate } = displaysDate()
    const { displayCurrency } = displaysCurrency()
    const totalDue = computed(() => displayCurrency(props.total))

    // Payment schedules
    const { getScheduleTotal } = invoicePaymentScheduleForm(props.invoice, props.total)

    return {
      school,
      displayCurrency,
      displayDate,
      timezone,
      totalDue,
      getScheduleTotal,
    }
  },
}
</script>
