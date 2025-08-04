export default {
    root: {
        class: [
            //Shape
            'rounded-md',
            'shadow-md',

            //Color
            'bg-surface-0 dark:bg-surface-900',
            'text-surface-700 dark:text-surface-0'
        ]
    },
    body: {
        class: 'p-5'
    },
    title: {
        class: 'text-xl font-bold mb-2'
    },
    subtitle: {
        class: [
            //Font
            'font-normal',

            //Spacing
            'mb-2',

            //Color
            'text-surface-600 dark:text-surface-0/60'
        ]
    },
    content: {
        class: '' // Vertical padding.
    },
    footer: {
        class: 'pt-5' // Top padding.
    }
};
